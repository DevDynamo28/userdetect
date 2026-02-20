<?php

namespace App\Services;

use App\Jobs\LearnFromDetection;
use App\Models\Client;
use App\Models\UserDetection;
use App\Models\UserFingerprint;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocationVerificationService
{
    /**
     * Backfill verified location labels for recent detections of a fingerprint.
     */
    public function verifyFingerprintLocation(Client $client, string $fingerprintId, array $payload): array
    {
        $city = trim((string) ($payload['city'] ?? ''));
        $state = trim((string) ($payload['state'] ?? ''));
        $country = trim((string) ($payload['country'] ?? config('detection.default_country', 'India')));
        $source = trim((string) ($payload['source'] ?? 'manual'));
        $backfillHours = (int) ($payload['backfill_hours'] ?? 72);
        $maxRecords = (int) ($payload['max_records'] ?? 100);
        $eventTimestamp = !empty($payload['event_timestamp'])
            ? Carbon::parse($payload['event_timestamp'])
            : now();

        $detections = UserDetection::query()
            ->forClient($client->id)
            ->where('fingerprint_id', $fingerprintId)
            ->where('detected_at', '>=', now()->subHours($backfillHours))
            ->orderByDesc('detected_at')
            ->limit($maxRecords)
            ->get();

        if ($detections->isEmpty()) {
            return $this->buildSummary(
                $fingerprintId,
                $city,
                $state !== '' ? $state : null,
                $country !== '' ? $country : null,
                $source,
                $backfillHours,
                $maxRecords,
                collect(),
                0
            );
        }

        $learningJobs = 0;
        DB::transaction(function () use (
            $detections,
            $city,
            $state,
            $country,
            $source,
            $eventTimestamp,
            &$learningJobs
        ) {
            foreach ($detections as $detection) {
                $isMatch = $this->doesVerificationMatch($detection, $city, $state, $country);

                $detection->fill([
                    'verified_city' => $city,
                    'verified_state' => $state !== '' ? $state : null,
                    'verified_country' => $country !== '' ? $country : null,
                    'is_location_verified' => $isMatch,
                    'verification_source' => $source,
                    'verification_received_at' => $eventTimestamp,
                ]);
                $detection->save();

                if (
                    $isMatch
                    && $detection->confidence >= config('detection.learning.min_confidence_to_learn', 80)
                    && config('detection.learning.enabled', true)
                ) {
                    LearnFromDetection::dispatch($detection);
                    $learningJobs++;
                }
            }
        });

        $this->updateFingerprintConfidence($client, $fingerprintId, $city, $state !== '' ? $state : null);

        return $this->buildSummary(
            $fingerprintId,
            $city,
            $state !== '' ? $state : null,
            $country !== '' ? $country : null,
            $source,
            $backfillHours,
            $maxRecords,
            $detections,
            $learningJobs
        );
    }

    private function doesVerificationMatch(UserDetection $detection, string $city, string $state, string $country): bool
    {
        if (!$detection->detected_city) {
            return false;
        }

        if (strcasecmp($detection->detected_city, $city) !== 0) {
            return false;
        }

        if ($state !== '' && $detection->detected_state && strcasecmp($detection->detected_state, $state) !== 0) {
            return false;
        }

        if ($country !== '' && $detection->detected_country && strcasecmp($detection->detected_country, $country) !== 0) {
            return false;
        }

        return true;
    }

    private function updateFingerprintConfidence(Client $client, string $fingerprintId, string $city, ?string $state): void
    {
        $fingerprint = UserFingerprint::query()
            ->where('client_id', $client->id)
            ->where('fingerprint_id', $fingerprintId)
            ->first();

        if (!$fingerprint) {
            return;
        }

        $fingerprint->updateTypicalLocation($city, $state);
        $fingerprint->boostTrustScore(3);
    }

    private function buildSummary(
        string $fingerprintId,
        string $city,
        ?string $state,
        ?string $country,
        string $source,
        int $backfillHours,
        int $maxRecords,
        Collection $detections,
        int $learningJobs
    ): array {
        $methodStats = [];
        $matchedCount = 0;

        foreach ($detections as $detection) {
            $method = $detection->detection_method ?: 'unknown';
            if (!isset($methodStats[$method])) {
                $methodStats[$method] = [
                    'method' => $method,
                    'total' => 0,
                    'matched' => 0,
                    'accuracy_rate' => 0.0,
                ];
            }

            $methodStats[$method]['total']++;
            if ($detection->is_location_verified) {
                $methodStats[$method]['matched']++;
                $matchedCount++;
            }
        }

        foreach ($methodStats as &$stats) {
            $stats['accuracy_rate'] = $stats['total'] > 0
                ? round(($stats['matched'] / $stats['total']) * 100, 1)
                : 0.0;
        }
        unset($stats);

        $annotatedCount = $detections->count();
        $mismatchedCount = max(0, $annotatedCount - $matchedCount);

        return [
            'fingerprint_id' => $fingerprintId,
            'verification' => [
                'city' => $city,
                'state' => $state,
                'country' => $country,
                'source' => $source,
            ],
            'window' => [
                'backfill_hours' => $backfillHours,
                'max_records' => $maxRecords,
            ],
            'results' => [
                'annotated_records' => $annotatedCount,
                'matched_records' => $matchedCount,
                'mismatched_records' => $mismatchedCount,
                'match_rate' => $annotatedCount > 0
                    ? round(($matchedCount / $annotatedCount) * 100, 1)
                    : 0.0,
                'learning_jobs_dispatched' => $learningJobs,
            ],
            'method_accuracy' => array_values($methodStats),
        ];
    }
}
