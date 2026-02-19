<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Signal Fusion Engine — combines ALL passive signals into a location prediction.
 * No single signal is trusted alone; confidence comes from agreement between multiple sources.
 */
class SignalFusionService
{
    public function __construct(
        private LanguageStateMapper $languageMapper,
        private LocalGeoIPService $localGeoIP,
        private ReverseDNSService $reverseDNS,
        private EnsembleIPService $ensembleIP,
    ) {
    }

    /**
     * Fuse all available signals into a single location prediction.
     */
    public function inferLocation(string $ip, array $signals, Request $request): array
    {
        $evidence = [];
        $fallbackReason = null;

        // 1. Cloudflare geo headers (most accurate passive source)
        $cfEvidence = $this->fromCloudflareHeaders($request);
        if ($cfEvidence)
            $evidence[] = $cfEvidence;

        // 2. Language-based state inference
        $langEvidence = $this->fromLanguageSignals($signals);
        if ($langEvidence)
            $evidence[] = $langEvidence;

        // 3. Regional font detection
        $fontEvidence = $this->fromFontSignals($signals);
        if ($fontEvidence)
            $evidence[] = $fontEvidence;

        // 4. Local GeoIP database
        $geoipEvidence = $this->fromLocalGeoIP($ip);
        if ($geoipEvidence)
            $evidence[] = $geoipEvidence;

        // 5. Reverse DNS
        $dnsEvidence = $this->fromReverseDNS($ip);
        if ($dnsEvidence)
            $evidence[] = $dnsEvidence;

        // 6. Ensemble IP APIs (last resort, only if we have weak evidence)
        $totalWeight = array_sum(array_column($evidence, 'weight'));
        if ($totalWeight < 30 || !$this->hasAnyCityEvidence($evidence)) {
            $fallbackReason = $totalWeight < 30 ? 'low_evidence_weight' : 'no_city_evidence';
            $apiEvidence = $this->fromEnsembleAPIs($ip);
            if ($apiEvidence)
                $evidence[] = $apiEvidence;
        }

        // Fuse all evidence
        $result = $this->fuseEvidence($evidence);
        $result['quality_telemetry'] = $this->buildQualityTelemetry($evidence, $result, $fallbackReason);

        Log::channel('detection')->info('Signal fusion result', [
            'ip' => $ip,
            'sources' => count($evidence),
            'city' => $result['city'],
            'state' => $result['state'],
            'confidence' => $result['confidence'],
            'method' => $result['method'],
            'fallback_reason' => $fallbackReason,
            'confidence_bucket' => $result['quality_telemetry']['confidence_bucket'],
        ]);

        return $result;
    }

    // ---- Signal Extractors ----

    /**
     * Extract location from Cloudflare headers.
     * CF Workers set these, or the CF-IPCountry header is always present.
     */
    private function fromCloudflareHeaders(Request $request): ?array
    {
        // CF Worker can inject these custom headers
        $cfCity = $request->header('CF-IPCity') ?? $request->header('X-CF-City');
        $cfRegion = $request->header('CF-IPRegion') ?? $request->header('X-CF-Region');
        $cfCountry = $request->header('CF-IPCountry') ?? $request->header('X-CF-Country');
        $cfLat = $request->header('X-CF-Latitude');
        $cfLng = $request->header('X-CF-Longitude');
        $cfTimezone = $request->header('X-CF-Timezone');

        if (!$cfCity && !$cfRegion) {
            return null;
        }

        $confidence = 0;
        $weight = 0;

        if ($cfCity) {
            $confidence = 88;
            $weight = 50;
        } elseif ($cfRegion) {
            $confidence = 70;
            $weight = 35;
        }

        Log::channel('detection')->info("Cloudflare geo: city={$cfCity}, region={$cfRegion}, country={$cfCountry}");

        return [
            'source' => 'cloudflare',
            'city' => $cfCity ? ucwords(strtolower(urldecode($cfCity))) : null,
            'state' => $cfRegion ? ucwords(strtolower(urldecode($cfRegion))) : null,
            'country' => $cfCountry,
            'latitude' => $cfLat ? (float) $cfLat : null,
            'longitude' => $cfLng ? (float) $cfLng : null,
            'confidence' => $confidence,
            'weight' => $weight,
        ];
    }

    /**
     * Infer state from browser language preferences.
     */
    private function fromLanguageSignals(array $signals): ?array
    {
        $langResult = $this->languageMapper->inferFromLanguages($signals);
        if (!$langResult)
            return null;

        return [
            'source' => 'language',
            'city' => null, // Language only gives state-level
            'state' => $langResult['primary_state'],
            'states' => $langResult['states'],
            'country' => 'India',
            'confidence' => $langResult['confidence'],
            'weight' => config('detection.signal_weights.language_inference', 25),
            'meta' => [
                'language' => $langResult['language'],
                'code' => $langResult['language_code'],
            ],
        ];
    }

    /**
     * Infer state from regional font detection.
     */
    private function fromFontSignals(array $signals): ?array
    {
        $fontResult = $this->languageMapper->inferFromFonts($signals);
        if (!$fontResult)
            return null;

        return [
            'source' => 'fonts',
            'city' => null,
            'state' => $fontResult['state'],
            'country' => 'India',
            'confidence' => $fontResult['confidence'],
            'weight' => config('detection.signal_weights.font_detection', 8),
        ];
    }

    /**
     * Local MaxMind GeoIP database lookup.
     */
    private function fromLocalGeoIP(string $ip): ?array
    {
        if (!$this->localGeoIP->isAvailable())
            return null;

        $result = $this->localGeoIP->lookup($ip);
        if (!$result)
            return null;

        return [
            'source' => 'local_geoip',
            'city' => $result['city'] ?? null,
            'state' => $result['state'] ?? null,
            'country' => $result['country'] ?? 'India',
            'latitude' => $result['latitude'] ?? null,
            'longitude' => $result['longitude'] ?? null,
            'confidence' => $result['confidence'] ?? 60,
            'weight' => config('detection.signal_weights.local_geoip', 18),
        ];
    }

    /**
     * Reverse DNS hostname parsing.
     */
    private function fromReverseDNS(string $ip): ?array
    {
        try {
            $hostname = gethostbyaddr($ip);
            if ($hostname === $ip)
                return null;
        } catch (\Throwable $e) {
            return null;
        }

        $result = $this->reverseDNS->extractCity($hostname);
        if (!$result)
            return null;

        return [
            'source' => 'reverse_dns',
            'city' => $result['city'],
            'state' => $result['state'],
            'country' => 'India',
            'confidence' => $result['confidence'],
            'weight' => config('detection.signal_weights.reverse_dns', 15),
        ];
    }

    /**
     * Ensemble IP API lookup (expensive, used as last resort).
     */
    private function fromEnsembleAPIs(string $ip): ?array
    {
        $result = $this->ensembleIP->lookup($ip);

        if (empty($result['city']) && empty($result['state'])) {
            return null;
        }

        return [
            'source' => 'ensemble_ip',
            'city' => $result['city'] ?? null,
            'state' => $result['state'] ?? null,
            'country' => 'India',
            'latitude' => $result['latitude'] ?? null,
            'longitude' => $result['longitude'] ?? null,
            'confidence' => $result['confidence'] ?? 50,
            'weight' => config('detection.signal_weights.ip_ensemble', 20),
            'meta' => [
                'agreement' => $result['agreement_count'] ?? 0,
                'total_sources' => $result['total_sources'] ?? 0,
                'asn' => $result['asn'] ?? null,
                'isp' => $result['isp'] ?? null,
            ],
            'sources_data' => $result['sources_data'] ?? null,
        ];
    }

    // ---- Fusion Algorithm ----

    /**
     * Fuse evidence from all sources using weighted consensus.
     * Sources that agree boost each other. Disagreements reduce confidence.
     */
    private function fuseEvidence(array $evidence): array
    {
        if (empty($evidence)) {
            return [
                'city' => null,
                'state' => null,
                'country' => 'India',
                'confidence' => 0,
                'method' => 'none',
                'latitude' => null,
                'longitude' => null,
                'sources_data' => null,
                'asn' => null,
                'isp' => null,
            ];
        }

        // ---- STATE FUSION ----
        $stateVotes = [];
        foreach ($evidence as $e) {
            $state = $e['state'] ?? null;
            if (!$state)
                continue;

            $normalizedState = $this->normalizeState($state);
            $weight = $e['weight'] ?? 1;

            if (!isset($stateVotes[$normalizedState])) {
                $stateVotes[$normalizedState] = ['weight' => 0, 'sources' => [], 'display' => $state];
            }
            $stateVotes[$normalizedState]['weight'] += $weight;
            $stateVotes[$normalizedState]['sources'][] = $e['source'];

            // Also count alternative states (for languages spoken in multiple states)
            if (!empty($e['states'])) {
                foreach ($e['states'] as $altState) {
                    $norm = $this->normalizeState($altState);
                    if ($norm === $normalizedState)
                        continue;
                    if (!isset($stateVotes[$norm])) {
                        $stateVotes[$norm] = ['weight' => 0, 'sources' => [], 'display' => $altState];
                    }
                    $stateVotes[$norm]['weight'] += $weight * 0.3; // Lower weight for alternatives
                }
            }
        }

        // Best state = highest total weight
        $bestState = null;
        $bestStateWeight = 0;
        $bestStateDisplay = null;
        foreach ($stateVotes as $state => $info) {
            if ($info['weight'] > $bestStateWeight) {
                $bestState = $state;
                $bestStateWeight = $info['weight'];
                $bestStateDisplay = $info['display'];
            }
        }

        // ---- CITY FUSION ----
        $cityVotes = [];
        $bestMethod = 'signal_fusion';
        $bestMethodWeight = 0;

        foreach ($evidence as $e) {
            $city = $e['city'] ?? null;
            if (!$city)
                continue;

            $normalizedCity = strtolower(trim($city));
            $weight = $e['weight'] ?? 1;

            // Boost city weight if its state matches the consensus state
            $cityState = $this->normalizeState($e['state'] ?? '');
            if ($bestState && $cityState === $bestState) {
                $weight *= 1.5; // 50% boost for state agreement
            }

            if (!isset($cityVotes[$normalizedCity])) {
                $cityVotes[$normalizedCity] = ['weight' => 0, 'sources' => [], 'display' => $city];
            }
            $cityVotes[$normalizedCity]['weight'] += $weight;
            $cityVotes[$normalizedCity]['sources'][] = $e['source'];

            if ($weight > $bestMethodWeight) {
                $bestMethodWeight = $weight;
                $bestMethod = $e['source'];
            }
        }

        // Best city = highest total weight (among cities whose state matches consensus)
        $bestCity = null;
        $bestCityWeight = 0;
        $bestCityDisplay = null;
        foreach ($cityVotes as $city => $info) {
            if ($info['weight'] > $bestCityWeight) {
                $bestCity = $city;
                $bestCityWeight = $info['weight'];
                $bestCityDisplay = $info['display'];
            }
        }

        // ---- CONFIDENCE CALCULATION ----
        $confidence = $this->calculateFusedConfidence($evidence, $bestState, $bestCityDisplay);

        // ---- COORDINATES ----
        $latitude = null;
        $longitude = null;
        foreach ($evidence as $e) {
            if (!empty($e['latitude']) && !empty($e['longitude'])) {
                // Prefer Cloudflare, then local GeoIP
                if ($e['source'] === 'cloudflare' || $latitude === null) {
                    $latitude = $e['latitude'];
                    $longitude = $e['longitude'];
                }
            }
        }

        // ---- ISP/ASN from ensemble or GeoIP ----
        $asn = null;
        $isp = null;
        $sourcesData = null;
        foreach ($evidence as $e) {
            if (!empty($e['meta']['asn']))
                $asn = $e['meta']['asn'];
            if (!empty($e['meta']['isp']))
                $isp = $e['meta']['isp'];
            if (!empty($e['sources_data']))
                $sourcesData = $e['sources_data'];
        }

        return [
            'city' => $bestCityDisplay,
            'state' => $bestStateDisplay,
            'country' => 'India',
            'confidence' => $confidence,
            'method' => $bestMethod,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'asn' => $asn,
            'isp' => $isp,
            'sources_data' => $sourcesData,
            'fusion_debug' => [
                'total_sources' => count($evidence),
                'state_votes' => array_map(fn($v) => ['weight' => round($v['weight'], 1), 'sources' => $v['sources']], $stateVotes),
                'city_votes' => array_map(fn($v) => ['weight' => round($v['weight'], 1), 'sources' => $v['sources']], $cityVotes),
            ],
        ];
    }

    /**
     * Calculate fused confidence based on source agreement.
     */
    private function calculateFusedConfidence(array $evidence, ?string $consensusState, ?string $consensusCity): int
    {
        if (empty($evidence))
            return 0;

        $stateAgreers = 0;
        $cityAgreers = 0;
        $totalSources = count($evidence);
        $totalWeight = 0;
        $agreeingWeight = 0;

        foreach ($evidence as $e) {
            $weight = $e['weight'] ?? 1;
            $totalWeight += $weight;

            $eState = $this->normalizeState($e['state'] ?? '');
            if ($consensusState && $eState === $consensusState) {
                $stateAgreers++;
                $agreeingWeight += $weight;
            }

            if ($consensusCity && strtolower(trim($e['city'] ?? '')) === strtolower(trim($consensusCity))) {
                $cityAgreers++;
            }
        }

        // Base confidence from agreement ratio
        $agreementRatio = $totalWeight > 0 ? $agreeingWeight / $totalWeight : 0;
        $baseConfidence = 30 + ($agreementRatio * 50); // 30–80 range

        // Bonus for city-level agreement
        if ($cityAgreers >= 2) {
            $baseConfidence += 10;
        }
        if ($cityAgreers >= 3) {
            $baseConfidence += 5;
        }

        // Bonus for diverse source types agreeing (strongest signal)
        $sourceTypes = [];
        foreach ($evidence as $e) {
            $eState = $this->normalizeState($e['state'] ?? '');
            if ($consensusState && $eState === $consensusState) {
                $sourceTypes[] = $e['source'];
            }
        }
        $uniqueTypes = count(array_unique($sourceTypes));
        if ($uniqueTypes >= 3) {
            $baseConfidence += 8; // Language + Cloudflare + IP all agree = very strong
        }

        // Cloudflare provides a significant confidence boost
        $hasCF = false;
        foreach ($evidence as $e) {
            if ($e['source'] === 'cloudflare' && !empty($e['city'])) {
                $hasCF = true;
                break;
            }
        }
        if ($hasCF) {
            $baseConfidence = max($baseConfidence, 85);
        }

        // Penalize when state signals are highly split between top contenders.
        $stateWeightMap = [];
        foreach ($evidence as $e) {
            $state = $this->normalizeState($e['state'] ?? '');
            if ($state === '') {
                continue;
            }

            $stateWeightMap[$state] = ($stateWeightMap[$state] ?? 0) + ($e['weight'] ?? 1);
        }

        if (count($stateWeightMap) > 1) {
            arsort($stateWeightMap);
            $topWeights = array_values($stateWeightMap);
            $top = $topWeights[0] ?? 0;
            $second = $topWeights[1] ?? 0;
            $ratio = $top > 0 ? ($second / $top) : 0;

            if ($ratio >= 0.6) {
                $baseConfidence -= 8;
            } elseif ($ratio >= 0.4) {
                $baseConfidence -= 4;
            }
        }

        return min(98, max(10, (int) $baseConfidence));
    }

    private function normalizeState(string $state): string
    {
        $state = strtolower(trim($state));

        // Common normalizations
        $aliases = [
            'karnataka' => 'karnataka',
            'tamilnadu' => 'tamil nadu',
            'tamil_nadu' => 'tamil nadu',
            'andhra pradesh' => 'andhra pradesh',
            'ap' => 'andhra pradesh',
            'ts' => 'telangana',
            'maharashtra' => 'maharashtra',
            'mh' => 'maharashtra',
            'gj' => 'gujarat',
            'up' => 'uttar pradesh',
            'mp' => 'madhya pradesh',
            'wb' => 'west bengal',
            'tn' => 'tamil nadu',
            'ka' => 'karnataka',
            'dl' => 'delhi',
            'nct of delhi' => 'delhi',
            'national capital territory of delhi' => 'delhi',
        ];

        return $aliases[$state] ?? $state;
    }

    private function hasAnyCityEvidence(array $evidence): bool
    {
        foreach ($evidence as $e) {
            if (!empty($e['city']))
                return true;
        }
        return false;
    }

    private function buildQualityTelemetry(array $evidence, array $result, ?string $fallbackReason): array
    {
        $stateEvidence = [];
        $cityEvidence = [];

        foreach ($evidence as $entry) {
            $source = $entry['source'] ?? 'unknown';
            $weight = (float) ($entry['weight'] ?? 1);

            if (!empty($entry['state'])) {
                $normalizedState = $this->normalizeState($entry['state']);
                $stateEvidence[$normalizedState]['weight'] = ($stateEvidence[$normalizedState]['weight'] ?? 0) + $weight;
                $stateEvidence[$normalizedState]['sources'][] = $source;
            }

            if (!empty($entry['city'])) {
                $city = strtolower(trim($entry['city']));
                $cityEvidence[$city]['weight'] = ($cityEvidence[$city]['weight'] ?? 0) + $weight;
                $cityEvidence[$city]['sources'][] = $source;
            }
        }

        $confidence = (int) ($result['confidence'] ?? 0);
        $confidenceBucket = match (true) {
            $confidence >= 85 => 'high',
            $confidence >= 70 => 'medium',
            $confidence >= 55 => 'low',
            default => 'very_low',
        };

        return [
            'source_participation' => array_values(array_map(fn($e) => $e['source'] ?? 'unknown', $evidence)),
            'state_disagreement_count' => max(0, count($stateEvidence) - 1),
            'city_disagreement_count' => max(0, count($cityEvidence) - 1),
            'fallback_reason' => $fallbackReason,
            'confidence_bucket' => $confidenceBucket,
        ];
    }
}
