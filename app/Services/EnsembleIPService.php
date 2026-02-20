<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EnsembleIPService
{
    private int $timeout;
    private int $connectTimeout;
    private float $clusterRadiusKm;
    private array $sourceWeights;
    private int $failureCircuitThreshold;
    private int $failureCircuitTtl;
    private bool $allowInsecureSources;
    private array $enabledSources;

    public function __construct()
    {
        $this->timeout = config('detection.methods.ensemble_ip.timeout', 3);
        $this->connectTimeout = config('detection.methods.ensemble_ip.connect_timeout', 2);
        $this->clusterRadiusKm = config('detection.methods.ensemble_ip.geo_cluster_radius_km', 50);
        $this->sourceWeights = config('detection.methods.ensemble_ip.source_weights', []);
        $this->failureCircuitThreshold = config('detection.methods.ensemble_ip.failure_circuit_threshold', 4);
        $this->failureCircuitTtl = config('detection.methods.ensemble_ip.failure_circuit_ttl_seconds', 120);
        $this->allowInsecureSources = (bool) config('detection.methods.ensemble_ip.allow_insecure_sources', false);
        $this->enabledSources = config('detection.methods.ensemble_ip.enabled_sources', []);
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
        if ($this->isCircuitOpen()) {
            Log::channel('detection')->warning('Ensemble circuit is open, skipping API lookup.');
            return $this->emptyResult($ip);
        }

        $sources = $this->getActiveSources();
        if (empty($sources)) {
            Log::channel('detection')->warning('No active ensemble sources configured.');
            return $this->emptyResult($ip);
        }
        $responses = [];

        $sourceKeys = array_keys($sources);

        try {
            // Make concurrent requests to all sources
            $httpResponses = Http::pool(fn($pool) => array_map(
                fn($key) => $pool->as($key)
                    ->connectTimeout($this->connectTimeout)
                    ->timeout($this->timeout)
                    ->get(str_replace('{ip}', $ip, $sources[$key])),
                $sourceKeys
            ));
        } catch (\Throwable $e) {
            $this->registerCircuitFailure();
            Log::channel('detection')->warning("Ensemble request pool failed for {$ip}: {$e->getMessage()}");
            return $this->emptyResult($ip);
        }

        // Normalize each response
        foreach ($sourceKeys as $source) {
            try {
                $response = $httpResponses[$source];
                if ($response instanceof \Illuminate\Http\Client\Response && $response->successful()) {
                    $normalized = $this->normalizeResponse($source, $response->json());
                    if ($normalized) {
                        $responses[$source] = $normalized;
                        Log::channel('detection')->info("Ensemble [{$source}] for {$ip}: city={$normalized['city']}, state={$normalized['state']}, lat={$normalized['latitude']}, lng={$normalized['longitude']}");
                    } else {
                        Log::channel('detection')->warning("Ensemble [{$source}] for {$ip}: normalized to null (invalid data)");
                    }
                } else {
                    $statusCode = $response instanceof \Illuminate\Http\Client\Response ? $response->status() : 'N/A';
                    Log::channel('detection')->warning("Ensemble [{$source}] for {$ip}: HTTP {$statusCode}");
                }
            } catch (\Throwable $e) {
                Log::channel('detection')->warning("Ensemble source {$source} failed for IP {$ip}: {$e->getMessage()}");
            }
        }

        $responseCount = count($responses);
        Log::channel('detection')->info("Ensemble for {$ip}: {$responseCount}/" . count($sourceKeys) . ' sources responded');

        if ($responseCount === 0) {
            $this->registerCircuitFailure();
        } else {
            $this->clearCircuitFailures();
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
            'ipwho' => $this->normalizeIpwho($data),
            'freeipapi' => $this->normalizeFreeipapi($data),
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
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
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
            'latitude' => isset($data['lat']) ? (float) $data['lat'] : null,
            'longitude' => isset($data['lon']) ? (float) $data['lon'] : null,
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
            'latitude' => isset($data['geoplugin_latitude']) ? (float) $data['geoplugin_latitude'] : null,
            'longitude' => isset($data['geoplugin_longitude']) ? (float) $data['geoplugin_longitude'] : null,
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
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'asn' => isset($data['connection']['asn']) ? 'AS' . $data['connection']['asn'] : null,
            'isp' => $data['connection']['isp'] ?? ($data['isp'] ?? null),
        ];
    }

    private function normalizeIpwho(array $data): ?array
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
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'asn' => isset($data['connection']['asn']) ? 'AS' . $data['connection']['asn'] : null,
            'isp' => $data['connection']['isp'] ?? ($data['connection']['org'] ?? null),
        ];
    }

    private function normalizeFreeipapi(array $data): ?array
    {
        if (empty($data['cityName']) && empty($data['regionName'])) {
            return null;
        }

        return [
            'city' => $data['cityName'] ?? null,
            'state' => $data['regionName'] ?? null,
            'country' => $data['countryName'] ?? null,
            'country_code' => $data['countryCode'] ?? null,
            'postal' => $data['zipCode'] ?? null,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'asn' => isset($data['asn']) ? 'AS' . $data['asn'] : null,
            'isp' => $data['asnOrganization'] ?? null,
        ];
    }

    private function calculateConsensus(array $responses, string $ip): array
    {
        if (empty($responses)) {
            Log::channel('detection')->warning("No ensemble sources returned data for IP {$ip}");
            return $this->emptyResult($ip);
        }

        // Collect data from all sources
        $sourcesData = [];
        $sourceEntries = []; // city, lat, lng, weight per source

        foreach ($responses as $source => $data) {
            $sourcesData[$source] = $data['city'] ?? 'unknown';
            $weight = $this->sourceWeights[$source] ?? 1.0;

            if (!empty($data['city'])) {
                $normalizedCity = $this->normalizeCity($data['city']);
                $sourceEntries[] = [
                    'source' => $source,
                    'city' => $normalizedCity,
                    'state' => $data['state'] ?? null,
                    'lat' => $data['latitude'] ?? null,
                    'lng' => $data['longitude'] ?? null,
                    'weight' => $weight,
                    'postal' => $data['postal'] ?? null,
                    'isp' => $data['isp'] ?? null,
                    'asn' => $data['asn'] ?? null,
                ];
            }
        }

        if (empty($sourceEntries)) {
            // No city from any source — try to at least get state
            $stateVotes = [];
            foreach ($responses as $data) {
                if (!empty($data['state'])) {
                    $stateVotes[$data['state']] = ($stateVotes[$data['state']] ?? 0) + 1;
                }
            }
            arsort($stateVotes);
            $state = !empty($stateVotes) ? array_key_first($stateVotes) : null;

            return array_merge($this->emptyResult($ip), [
                'state' => $state,
                'country' => $this->mostCommon(array_filter(array_column($responses, 'country'))) ?? config('detection.default_country', 'India'),
                'sources_data' => $sourcesData,
            ]);
        }
        // STEP 0: State-based outlier detection — penalize sources whose state disagrees with majority
        $stateVotes = [];
        foreach ($sourceEntries as $entry) {
            if (!empty($entry['state'])) {
                $stateVotes[$entry['state']] = ($stateVotes[$entry['state']] ?? 0) + 1;
            }
        }
        arsort($stateVotes);
        $majorityState = !empty($stateVotes) ? array_key_first($stateVotes) : null;
        $majorityStateCount = $majorityState ? $stateVotes[$majorityState] : 0;

        // If majority state has 3+ sources agreeing, penalize outliers
        if ($majorityState && $majorityStateCount >= 3) {
            foreach ($sourceEntries as &$entry) {
                if (!empty($entry['state']) && $entry['state'] !== $majorityState) {
                    $oldWeight = $entry['weight'];
                    $entry['weight'] *= 0.3; // Heavy penalty for state mismatch
                    Log::channel('detection')->info("Ensemble outlier: [{$entry['source']}] state={$entry['state']} disagrees with majority={$majorityState}, weight {$oldWeight}→{$entry['weight']}");
                }
            }
            unset($entry);
        }

        // STEP 1: Geo-cluster sources by lat/lng proximity
        $clusters = $this->buildGeoClusters($sourceEntries);

        // STEP 2: Find the best cluster (highest total weight)
        $bestCluster = $this->selectBestCluster($clusters);

        // STEP 3: Determine city from the best cluster
        $city = $this->getMostVotedCity($bestCluster);
        $agreementCount = count($bestCluster);
        $totalSources = count($sourceEntries);

        $clusterSources = array_column($bestCluster, 'source');
        $clusterCities = array_unique(array_column($bestCluster, 'city'));
        Log::channel('detection')->info("Ensemble consensus for {$ip}: {$agreementCount}/{$totalSources} sources in best cluster, city={$city}, clusters=" . count($clusters) . ", sources=[" . implode(',', $clusterSources) . "], cities=[" . implode(',', $clusterCities) . "]");

        // STEP 4: Calculate confidence based on agreement ratio and source weights
        $totalWeight = array_sum(array_column($bestCluster, 'weight'));
        $maxPossibleWeight = array_sum(array_column($sourceEntries, 'weight'));
        $weightRatio = $maxPossibleWeight > 0 ? $totalWeight / $maxPossibleWeight : 0;

        $confidence = match (true) {
            $agreementCount >= 5 && $weightRatio >= 0.8 => 95,
            $agreementCount >= 4 && $weightRatio >= 0.7 => 90,
            $agreementCount >= 4 => 85,
            $agreementCount >= 3 && $weightRatio >= 0.6 => 80,
            $agreementCount >= 3 => 75,
            $agreementCount >= 2 && $weightRatio >= 0.5 => 70,
            $agreementCount >= 2 => 65,
            $weightRatio >= 0.3 => 55,
            default => 45,
        };

        // STEP 5: Get best lat/lng (weighted average from cluster, prefer high-weight sources)
        $latitude = $this->weightedAverageCoord($bestCluster, 'lat');
        $longitude = $this->weightedAverageCoord($bestCluster, 'lng');

        // State consensus from cluster
        $stateVotes = [];
        $countryVotes = [];
        foreach ($bestCluster as $entry) {
            if (!empty($entry['state'])) {
                $stateVotes[$entry['state']] = ($stateVotes[$entry['state']] ?? 0) + $entry['weight'];
            }
            if (!empty($responses[$entry['source']]['country'])) {
                $country = $responses[$entry['source']]['country'];
                $countryVotes[$country] = ($countryVotes[$country] ?? 0) + $entry['weight'];
            }
        }
        arsort($stateVotes);
        arsort($countryVotes);
        $state = !empty($stateVotes) ? array_key_first($stateVotes) : null;
        $country = !empty($countryVotes) ? array_key_first($countryVotes) : (config('detection.default_country', 'India'));

        // ISP / ASN from all responses
        $ispValues = array_filter(array_column($sourceEntries, 'isp'));
        $asnValues = array_filter(array_column($sourceEntries, 'asn'));
        $postalValues = array_filter(array_column($sourceEntries, 'postal'));

        $asn = $this->mostCommon($asnValues);
        $connectionType = $this->inferConnectionType($asn);

        // Build city votes for alternatives
        $cityVotes = [];
        foreach ($sourceEntries as $entry) {
            $cityVotes[$entry['city']] = ($cityVotes[$entry['city']] ?? 0) + $entry['weight'];
        }
        arsort($cityVotes);

        $minSourcesRequired = (int) config('detection.methods.ensemble_ip.min_sources_required', 2);
        if ($agreementCount < $minSourcesRequired) {
            $confidence = min($confidence, 54);
        }

        return [
            'city' => $confidence >= 55 ? $city : null,
            'state' => $state,
            'country' => $country,
            'confidence' => $confidence,
            'agreement_count' => $agreementCount,
            'total_sources' => $totalSources,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'postal' => $this->mostCommon($postalValues),
            'isp' => $this->mostCommon($ispValues),
            'asn' => $asn,
            'connection_type' => $connectionType,
            'sources_data' => $sourcesData,
            'alternatives' => $this->getAlternatives($cityVotes, $city),
        ];
    }

    /**
     * Group source entries into geographic clusters based on lat/lng proximity.
     * Sources without coordinates are matched by city name to existing clusters.
     */
    private function buildGeoClusters(array $entries): array
    {
        $clusters = [];

        // First pass: entries with coordinates
        $withCoords = array_filter($entries, fn($e) => $e['lat'] !== null && $e['lng'] !== null);
        $withoutCoords = array_filter($entries, fn($e) => $e['lat'] === null || $e['lng'] === null);

        foreach ($withCoords as $entry) {
            $placed = false;
            foreach ($clusters as &$cluster) {
                // Check distance to cluster centroid
                $centroid = $this->clusterCentroid($cluster);
                $distance = $this->haversineDistance($entry['lat'], $entry['lng'], $centroid['lat'], $centroid['lng']);

                if ($distance <= $this->clusterRadiusKm) {
                    $cluster[] = $entry;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);

            if (!$placed) {
                $clusters[] = [$entry];
            }
        }

        // Second pass: entries without coordinates — match by city name
        foreach ($withoutCoords as $entry) {
            $placed = false;
            foreach ($clusters as &$cluster) {
                foreach ($cluster as $member) {
                    if (strtolower($member['city']) === strtolower($entry['city'])) {
                        $cluster[] = $entry;
                        $placed = true;
                        break 2;
                    }
                }
            }
            unset($cluster);

            if (!$placed) {
                $clusters[] = [$entry];
            }
        }

        return $clusters;
    }

    private function clusterCentroid(array $cluster): array
    {
        $lats = array_filter(array_column($cluster, 'lat'));
        $lngs = array_filter(array_column($cluster, 'lng'));

        return [
            'lat' => !empty($lats) ? array_sum($lats) / count($lats) : 0,
            'lng' => !empty($lngs) ? array_sum($lngs) / count($lngs) : 0,
        ];
    }

    /**
     * Calculate distance between two lat/lng points in km using the Haversine formula.
     */
    private function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function selectBestCluster(array $clusters): array
    {
        if (empty($clusters)) {
            return [];
        }

        // Select cluster with highest total weight (pre-calculate to avoid redundant sums)
        $bestCluster = $clusters[0];
        $bestWeight = array_sum(array_column($clusters[0], 'weight'));

        for ($i = 1; $i < count($clusters); $i++) {
            $weight = array_sum(array_column($clusters[$i], 'weight'));
            if ($weight > $bestWeight) {
                $bestWeight = $weight;
                $bestCluster = $clusters[$i];
            }
        }

        return $bestCluster;
    }

    /**
     * Get the most voted city within a cluster, weighted.
     */
    private function getMostVotedCity(array $cluster): ?string
    {
        $votes = [];
        foreach ($cluster as $entry) {
            $votes[$entry['city']] = ($votes[$entry['city']] ?? 0) + $entry['weight'];
        }
        arsort($votes);

        return !empty($votes) ? array_key_first($votes) : null;
    }

    private function weightedAverageCoord(array $cluster, string $key): ?float
    {
        $values = [];
        $weights = [];

        foreach ($cluster as $entry) {
            if ($entry[$key] !== null) {
                $values[] = $entry[$key];
                $weights[] = $entry['weight'];
            }
        }

        if (empty($values)) {
            return null;
        }

        $totalWeight = array_sum($weights);
        $weightedSum = 0;
        for ($i = 0; $i < count($values); $i++) {
            $weightedSum += $values[$i] * $weights[$i];
        }

        return round($weightedSum / $totalWeight, 6);
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
            'Trivandrum' => 'Thiruvananthapuram',
            'Cochin' => 'Kochi',
            'Vizag' => 'Visakhapatnam',
            'Simla' => 'Shimla',
            'Pondicherry' => 'Puducherry',
            'Allahabad' => 'Prayagraj',
            'Mangalore' => 'Mangaluru',
            'Mysore' => 'Mysuru',
            'Benaras' => 'Varanasi',
            'Gurgaon' => 'Gurugram',
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

        foreach ($cityVotes as $city => $weight) {
            $alternatives[] = [
                'city' => $city,
                'probability' => $total > 0 ? round(($weight / $total) * 100) : 0,
            ];
        }

        return array_slice($alternatives, 0, 3);
    }

    private function emptyResult(string $ip): array
    {
        return [
            'city' => null,
            'state' => null,
            'country' => config('detection.default_country', 'India'),
            'confidence' => 0,
            'agreement_count' => 0,
            'total_sources' => 0,
            'latitude' => null,
            'longitude' => null,
            'postal' => null,
            'isp' => null,
            'asn' => null,
            'connection_type' => 'unknown',
            'sources_data' => [],
            'alternatives' => [],
        ];
    }

    private function isCircuitOpen(): bool
    {
        try {
            return (bool) Cache::get($this->circuitOpenKey(), false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function registerCircuitFailure(): void
    {
        try {
            $count = (int) Cache::increment($this->circuitFailureKey());
            if ($count === 1) {
                Cache::put($this->circuitFailureKey(), 1, now()->addSeconds($this->failureCircuitTtl));
                $count = 1;
            }

            if ($count >= $this->failureCircuitThreshold) {
                Cache::put($this->circuitOpenKey(), true, now()->addSeconds($this->failureCircuitTtl));
                Log::channel('detection')->warning("Ensemble circuit opened after {$count} consecutive failures.");
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->warning("Failed to update ensemble circuit state: {$e->getMessage()}");
        }
    }

    private function clearCircuitFailures(): void
    {
        try {
            Cache::forget($this->circuitFailureKey());
            Cache::forget($this->circuitOpenKey());
        } catch (\Throwable $e) {
            Log::channel('detection')->warning("Failed to clear ensemble circuit state: {$e->getMessage()}");
        }
    }

    private function circuitFailureKey(): string
    {
        return 'ensemble:circuit:failures';
    }

    private function circuitOpenKey(): string
    {
        return 'ensemble:circuit:open';
    }

    private function getActiveSources(): array
    {
        $sources = config('detection.methods.ensemble_ip.sources', []);
        if (empty($sources)) {
            return [];
        }

        $enabled = array_fill_keys($this->enabledSources, true);

        return array_filter($sources, function (string $url, string $key) use ($enabled) {
            if (!empty($enabled) && !isset($enabled[$key])) {
                return false;
            }

            $isInsecure = str_starts_with(strtolower($url), 'http://');
            if ($isInsecure && !$this->allowInsecureSources) {
                return false;
            }

            return true;
        }, ARRAY_FILTER_USE_BOTH);
    }
}
