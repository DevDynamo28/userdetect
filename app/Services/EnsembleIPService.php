<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnsembleIPService
{
    private int $timeout;

    public function __construct()
    {
        $this->timeout = config('detection.methods.ensemble_ip.timeout', 2);
    }

    /**
     * Perform ensemble IP geolocation lookup using multiple free APIs.
     */
    public function lookup(string $ip): array
    {
        $cacheTtl = config('detection.cache.ensemble_ttl', 3600);

        try {
            return Cache::remember("ip_geo:{$ip}", $cacheTtl, function () use ($ip) {
                return $this->performLookup($ip);
            });
        } catch (\Throwable $e) {
            // If cache (Redis) is down, perform lookup directly
            Log::channel('detection')->warning("Cache unavailable, performing direct lookup: {$e->getMessage()}");
            return $this->performLookup($ip);
        }
    }

    private function performLookup(string $ip): array
    {
        $sources = config('detection.methods.ensemble_ip.sources');
        $responses = [];

        // Make concurrent requests to all sources
        $httpResponses = Http::pool(fn ($pool) => [
            $pool->as('ipapi')
                ->timeout($this->timeout)
                ->get(str_replace('{ip}', $ip, $sources['ipapi'])),
            $pool->as('ip-api')
                ->timeout($this->timeout)
                ->get(str_replace('{ip}', $ip, $sources['ip-api'])),
            $pool->as('geoplugin')
                ->timeout($this->timeout)
                ->get(str_replace('{ip}', $ip, $sources['geoplugin'])),
            $pool->as('ipwhois')
                ->timeout($this->timeout)
                ->get(str_replace('{ip}', $ip, $sources['ipwhois'])),
        ]);

        // Normalize each response
        foreach (['ipapi', 'ip-api', 'geoplugin', 'ipwhois'] as $source) {
            try {
                $response = $httpResponses[$source];
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $normalized = $this->normalizeResponse($source, $response->json());
                    if ($normalized) {
                        $responses[$source] = $normalized;
                    }
                }
            } catch (\Throwable $e) {
                Log::channel('detection')->warning("Ensemble source {$source} failed for IP {$ip}: {$e->getMessage()}");
            }
        }

        return $this->calculateConsensus($responses, $ip);
    }

    private function normalizeResponse(string $source, ?array $data): ?array
    {
        if (empty($data)) {
            return null;
        }

        return match ($source) {
            'ipapi' => $this->normalizeIpapi($data),
            'ip-api' => $this->normalizeIpApiCom($data),
            'geoplugin' => $this->normalizeGeoplugin($data),
            'ipwhois' => $this->normalizeIpwhois($data),
            default => null,
        };
    }

    private function normalizeIpapi(array $data): ?array
    {
        if (isset($data['error'])) {
            return null;
        }

        return [
            'city' => $data['city'] ?? null,
            'state' => $data['region'] ?? null,
            'country' => $data['country_name'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'postal' => $data['postal'] ?? null,
            'asn' => $data['asn'] ?? null,
            'isp' => $data['org'] ?? null,
        ];
    }

    private function normalizeIpApiCom(array $data): ?array
    {
        if (($data['status'] ?? '') === 'fail') {
            return null;
        }

        return [
            'city' => $data['city'] ?? null,
            'state' => $data['regionName'] ?? null,
            'country' => $data['country'] ?? null,
            'country_code' => $data['countryCode'] ?? null,
            'postal' => $data['zip'] ?? null,
            'asn' => !empty($data['as']) ? explode(' ', $data['as'])[0] : null,
            'isp' => $data['isp'] ?? null,
        ];
    }

    private function normalizeGeoplugin(array $data): ?array
    {
        if (empty($data['geoplugin_city']) && empty($data['geoplugin_region'])) {
            return null;
        }

        return [
            'city' => $data['geoplugin_city'] ?? null,
            'state' => $data['geoplugin_region'] ?? null,
            'country' => $data['geoplugin_countryName'] ?? null,
            'country_code' => $data['geoplugin_countryCode'] ?? null,
            'postal' => null,
            'asn' => null,
            'isp' => null,
        ];
    }

    private function normalizeIpwhois(array $data): ?array
    {
        if (($data['success'] ?? true) === false) {
            return null;
        }

        return [
            'city' => $data['city'] ?? null,
            'state' => $data['region'] ?? null,
            'country' => $data['country'] ?? null,
            'country_code' => $data['country_code'] ?? null,
            'postal' => $data['postal'] ?? null,
            'asn' => $data['connection']['asn'] ?? null,
            'isp' => $data['connection']['isp'] ?? ($data['isp'] ?? null),
        ];
    }

    private function calculateConsensus(array $responses, string $ip): array
    {
        if (empty($responses)) {
            Log::channel('detection')->warning("No ensemble sources returned data for IP {$ip}");
            return $this->emptyResult($ip);
        }

        // Collect all city votes
        $cityVotes = [];
        $stateVotes = [];
        $ispValues = [];
        $asnValues = [];
        $postalValues = [];
        $sourcesData = [];

        foreach ($responses as $source => $data) {
            $sourcesData[$source] = $data['city'] ?? 'unknown';

            if (!empty($data['city'])) {
                $normalizedCity = $this->normalizeCity($data['city']);
                $cityVotes[$normalizedCity] = ($cityVotes[$normalizedCity] ?? 0) + 1;
            }
            if (!empty($data['state'])) {
                $stateVotes[$data['state']] = ($stateVotes[$data['state']] ?? 0) + 1;
            }
            if (!empty($data['isp'])) {
                $ispValues[] = $data['isp'];
            }
            if (!empty($data['asn'])) {
                $asnValues[] = $data['asn'];
            }
            if (!empty($data['postal'])) {
                $postalValues[] = $data['postal'];
            }
        }

        // Determine city by weighted voting
        $city = null;
        $agreementCount = 0;
        $confidence = 50;

        if (!empty($cityVotes)) {
            arsort($cityVotes);
            $topCity = array_key_first($cityVotes);
            $agreementCount = $cityVotes[$topCity];
            $totalSources = count($responses);

            $city = $topCity;
            $confidence = match (true) {
                $agreementCount >= 4 => 85,
                $agreementCount >= 3 => 75,
                $agreementCount >= 2 => 65,
                default => 50,
            };
        }

        // State consensus
        $state = null;
        if (!empty($stateVotes)) {
            arsort($stateVotes);
            $state = array_key_first($stateVotes);
        }

        // Connection type
        $asn = $asnValues[0] ?? null;
        $connectionType = $this->inferConnectionType($asn);

        return [
            'city' => $confidence >= 60 ? $city : null,
            'state' => $state,
            'country' => 'India',
            'confidence' => $confidence,
            'agreement_count' => $agreementCount,
            'postal' => $postalValues[0] ?? null,
            'isp' => $this->mostCommon($ispValues),
            'asn' => $asn,
            'connection_type' => $connectionType,
            'sources_data' => $sourcesData,
            'alternatives' => $this->getAlternatives($cityVotes, $city),
        ];
    }

    private function normalizeCity(string $city): string
    {
        // Normalize common variations
        $city = trim($city);
        $map = [
            'Bengaluru' => 'Bangalore',
            'Bombay' => 'Mumbai',
            'Calcutta' => 'Kolkata',
            'Madras' => 'Chennai',
            'Poona' => 'Pune',
            'Baroda' => 'Vadodara',
        ];

        return $map[$city] ?? $city;
    }

    private function inferConnectionType(?string $asn): string
    {
        if (!$asn) {
            return 'unknown';
        }

        $mobileAsns = config('detection.mobile_asns', []);

        return in_array($asn, $mobileAsns) ? 'mobile' : 'broadband';
    }

    private function mostCommon(array $values): ?string
    {
        if (empty($values)) {
            return null;
        }

        $counts = array_count_values($values);
        arsort($counts);

        return array_key_first($counts);
    }

    private function getAlternatives(array $cityVotes, ?string $topCity): array
    {
        $alternatives = [];
        $total = array_sum($cityVotes);

        foreach ($cityVotes as $city => $count) {
            $alternatives[] = [
                'city' => $city,
                'probability' => $total > 0 ? round(($count / $total) * 100) : 0,
            ];
        }

        return array_slice($alternatives, 0, 3);
    }

    private function emptyResult(string $ip): array
    {
        return [
            'city' => null,
            'state' => null,
            'country' => 'India',
            'confidence' => 0,
            'agreement_count' => 0,
            'postal' => null,
            'isp' => null,
            'asn' => null,
            'connection_type' => 'unknown',
            'sources_data' => [],
            'alternatives' => [],
        ];
    }
}
