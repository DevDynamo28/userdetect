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

        if (!$detection->detected_city || $detection->confidence < config('detection.learning.min_confidence_to_learn', 80)) {
            return;
        }

        $cidr = $this->ipToCidr($detection->ip_address);
        if (!$cidr) {
            return;
        }

        try {
            $existing = DB::selectOne(
                'SELECT * FROM ip_range_learnings WHERE ip_range = ?::cidr LIMIT 1',
                [$cidr]
            );

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
    public function checkIPRangeLearnings(string $ip): ?array
    {
        if (!config('detection.learning.enabled', true)) {
            return null;
        }

        try {
            $result = DB::selectOne(
                'SELECT learned_city, learned_state, success_rate, sample_count
                 FROM ip_range_learnings
                 WHERE ip_range >>= ?::inet
                   AND is_active = true
                   AND sample_count >= ?
                   AND success_rate >= ?
                 ORDER BY sample_count DESC
                 LIMIT 1',
                [
                    $ip,
                    config('detection.learning.min_samples_for_active', 10),
                    config('detection.learning.min_success_rate', 70),
                ]
            );

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

            DB::update(
                'UPDATE ip_range_learnings
                 SET sample_count = ?,
                     success_rate = ?,
                     average_confidence = ?,
                     last_seen = NOW(),
                     is_active = CASE WHEN ? >= ? AND ? >= ? THEN true ELSE is_active END
                 WHERE id = ?',
                [
                    $newSampleCount,
                    $newSuccessRate,
                    round($newAvgConfidence, 2),
                    $newSampleCount, config('detection.learning.min_samples_for_active', 10),
                    $newSuccessRate, config('detection.learning.min_success_rate', 70),
                    $existing->id,
                ]
            );
        } else {
            // City differs — reduce reliability
            $consistentCount = round(($existing->success_rate / 100) * $existing->sample_count);
            $newSuccessRate = round(($consistentCount / $newSampleCount) * 100, 2);

            $isActive = $newSuccessRate >= 60; // Deactivate if too unreliable

            DB::update(
                'UPDATE ip_range_learnings
                 SET sample_count = ?,
                     success_rate = ?,
                     last_seen = NOW(),
                     is_active = ?
                 WHERE id = ?',
                [$newSampleCount, $newSuccessRate, $isActive, $existing->id]
            );

            Log::channel('detection')->info(
                "IP range learning conflict: {$existing->learned_city} vs {$detection->detected_city} for range, success_rate now {$newSuccessRate}%"
            );
        }
    }

    private function createNew(string $cidr, UserDetection $detection): void
    {
        DB::insert(
            'INSERT INTO ip_range_learnings (id, ip_range, learned_city, learned_state, sample_count, success_rate, average_confidence, primary_isp, primary_asn, reverse_dns_pattern, first_seen, last_seen, is_active)
             VALUES (gen_random_uuid(), ?::cidr, ?, ?, 1, 100.00, ?, ?, ?, ?, NOW(), NOW(), false)',
            [
                $cidr,
                $detection->detected_city,
                $detection->detected_state ?? 'Unknown',
                $detection->confidence,
                $detection->isp,
                $detection->asn,
                $detection->reverse_dns,
            ]
        );
    }
}
