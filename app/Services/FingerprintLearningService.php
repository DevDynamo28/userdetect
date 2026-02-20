<?php

namespace App\Services;

use App\Models\IpRangeLearning;
use App\Models\UserDetection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FingerprintLearningService
{
    /**
     * Learn IP range → city mapping from a high-confidence detection.
     */
    public function learnIPRange(UserDetection $detection): void
    {
        if (!config('detection.learning.enabled', true)) {
            return;
        }

        if (!$detection->client_id) {
            return;
        }

        if (config('detection.learning.require_verified_location', true) && !$detection->is_location_verified) {
            return;
        }

        if (!$detection->detected_city || $detection->confidence < config('detection.learning.min_confidence_to_learn', 80)) {
            return;
        }

        $cidr = $this->ipToCidr($detection->ip_address);
        if (!$cidr) {
            return;
        }

        try {
            $existing = $this->findExistingRange($detection->client_id, $cidr);

            if ($existing) {
                $this->updateExisting($existing, $detection);
            } else {
                $this->createNew($cidr, $detection);
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->error("Failed to learn IP range {$cidr}: {$e->getMessage()}");
        }
    }

    /**
     * Check if we have learned data for an IP address.
     */
    public function checkIPRangeLearnings(string $clientId, string $ip): ?array
    {
        if (!config('detection.learning.enabled', true)) {
            return null;
        }

        try {
            if ($this->isPostgres()) {
                $result = DB::selectOne(
                    'SELECT learned_city, learned_state, success_rate, sample_count
                     FROM ip_range_learnings
                     WHERE (client_id = ? OR client_id IS NULL)
                       AND ip_range >>= ?::inet
                       AND is_active = true
                       AND sample_count >= ?
                       AND success_rate >= ?
                     ORDER BY CASE WHEN client_id = ? THEN 0 ELSE 1 END, sample_count DESC
                     LIMIT 1',
                    [
                        $clientId,
                        $ip,
                        config('detection.learning.min_samples_for_active', 10),
                        config('detection.learning.min_success_rate', 70),
                        $clientId,
                    ]
                );
            } else {
                $cidr = $this->ipToCidr($ip);
                $result = IpRangeLearning::query()
                    ->select(['learned_city', 'learned_state', 'success_rate', 'sample_count'])
                    ->where(fn($q) => $q->where('client_id', $clientId)->orWhereNull('client_id'))
                    ->where('is_active', true)
                    ->where('sample_count', '>=', config('detection.learning.min_samples_for_active', 10))
                    ->where('success_rate', '>=', config('detection.learning.min_success_rate', 70))
                    ->when($cidr, fn($q) => $q->where('ip_range', $cidr))
                    ->orderByRaw('CASE WHEN client_id = ? THEN 0 ELSE 1 END', [$clientId])
                    ->orderByDesc('sample_count')
                    ->first();
            }

            if ($result) {
                return [
                    'city' => $result->learned_city,
                    'state' => $result->learned_state,
                    'confidence' => (int) $result->success_rate,
                    'sample_count' => $result->sample_count,
                ];
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->warning("Failed to check IP range learnings for {$ip}: {$e->getMessage()}");
        }

        return null;
    }

    /**
     * Convert an IP address to a /24 CIDR range.
     */
    private function ipToCidr(string $ip): ?string
    {
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return null;
        }

        $mask = config('detection.learning.ip_range_cidr_mask', 24);

        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/{$mask}";
    }

    private function updateExisting(object $existing, UserDetection $detection): void
    {
        $cityMatches = strtolower($existing->learned_city) === strtolower($detection->detected_city);
        $newSampleCount = $existing->sample_count + 1;

        if ($cityMatches) {
            // City matches — reinforce learning
            $consistentCount = round(($existing->success_rate / 100) * $existing->sample_count) + 1;
            $newSuccessRate = round(($consistentCount / $newSampleCount) * 100, 2);
            $newAvgConfidence = (($existing->average_confidence ?? $detection->confidence) * $existing->sample_count + $detection->confidence) / $newSampleCount;

            IpRangeLearning::query()
                ->where('id', $existing->id)
                ->update([
                    'sample_count' => $newSampleCount,
                    'success_rate' => $newSuccessRate,
                    'average_confidence' => round($newAvgConfidence, 2),
                    'last_seen' => now(),
                    'is_active' => $newSampleCount >= config('detection.learning.min_samples_for_active', 10)
                        && $newSuccessRate >= config('detection.learning.min_success_rate', 70),
                ]);
        } else {
            // City differs — reduce reliability
            $consistentCount = round(($existing->success_rate / 100) * $existing->sample_count);
            $newSuccessRate = round(($consistentCount / $newSampleCount) * 100, 2);

            $isActive = $newSuccessRate >= 60; // Deactivate if too unreliable

            IpRangeLearning::query()
                ->where('id', $existing->id)
                ->update([
                    'sample_count' => $newSampleCount,
                    'success_rate' => $newSuccessRate,
                    'last_seen' => now(),
                    'is_active' => $isActive,
                ]);

            Log::channel('detection')->info(
                "IP range learning conflict: {$existing->learned_city} vs {$detection->detected_city} for range, success_rate now {$newSuccessRate}%"
            );
        }
    }

    private function createNew(string $cidr, UserDetection $detection): void
    {
        IpRangeLearning::query()->create([
            'client_id' => $detection->client_id,
            'ip_range' => $cidr,
            'learned_city' => $detection->detected_city,
            'learned_state' => $detection->detected_state ?? 'Unknown',
            'sample_count' => 1,
            'success_rate' => 100.00,
            'average_confidence' => $detection->confidence,
            'primary_isp' => $detection->isp,
            'primary_asn' => $detection->asn,
            'reverse_dns_pattern' => $detection->reverse_dns,
            'first_seen' => now(),
            'last_seen' => now(),
            'is_active' => false,
        ]);
    }

    private function findExistingRange(string $clientId, string $cidr): ?object
    {
        if ($this->isPostgres()) {
            return DB::selectOne(
                'SELECT * FROM ip_range_learnings WHERE client_id = ? AND ip_range = ?::cidr LIMIT 1',
                [$clientId, $cidr]
            );
        }

        return IpRangeLearning::query()
            ->where('client_id', $clientId)
            ->where('ip_range', $cidr)
            ->first();
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
