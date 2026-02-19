<?php

namespace App\Services;

use App\Jobs\LearnFromDetection;
use App\Models\Client;
use App\Models\UserDetection;
use App\Models\UserFingerprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DetectionService
{
    public function __construct(
        private ReverseGeocodeService $reverseGeocode,
        private LocalGeoIPService $localGeoIP,
        private ReverseDNSService $reverseDNS,
        private EnsembleIPService $ensembleIP,
        private VPNDetectionService $vpnDetection,
        private FingerprintLearningService $learning,
    ) {
    }

    /**
     * Main detection flow — orchestrates all detection methods.
     */
    public function detect(Request $request, Client $client): array
    {
        $startTime = microtime(true);
        $requestId = 'req_' . Str::random(12);

        // STEP 1: Get IP and basic info
        $ip = $request->ip();
        $signals = $request->input('signals', []);
        $fingerprintId = $signals['fingerprint'] ?? null;
        $userAgent = $signals['user_agent'] ?? $request->userAgent();

        // In local/testing, allow IP override via X-Test-IP header or .env
        if (app()->environment('local', 'testing') && $this->isPrivateIP($ip)) {
            $testIp = $request->header('X-Test-IP') ?? env('TEST_IP');
            if ($testIp) {
                $ip = $testIp;
                Log::channel('detection')->info("Using test IP override: {$ip}");
            }
        }

        // Get reverse DNS
        $hostname = null;
        try {
            $hostname = gethostbyaddr($ip);
            if ($hostname === $ip) {
                $hostname = null; // gethostbyaddr returns IP if lookup fails
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->warning("Reverse DNS lookup failed for {$ip}: {$e->getMessage()}");
        }

        // STEP 2: Check fingerprint history
        $fingerprint = null;
        $isNewUser = true;
        if ($fingerprintId) {
            $fingerprint = UserFingerprint::where('client_id', $client->id)
                ->where('fingerprint_id', $fingerprintId)
                ->first();

            if ($fingerprint) {
                $isNewUser = false;
            }
        }

        // STEP 3: Check learned IP ranges
        $learnedResult = $this->learning->checkIPRangeLearnings($ip);

        // STEP 3.5: HIGHEST PRIORITY — Browser GPS Geolocation
        $detectedCity = null;
        $detectedState = null;
        $confidence = 0;
        $method = 'unknown';
        $ensembleData = null;
        $ensembleResult = [];
        $localGeoResult = null;
        $geoLocation = $signals['geolocation'] ?? null;

        if ($geoLocation && !empty($geoLocation['latitude']) && !empty($geoLocation['longitude'])) {
            $gpsResult = $this->reverseGeocode->reverseGeocode(
                (float) $geoLocation['latitude'],
                (float) $geoLocation['longitude']
            );

            if ($gpsResult && !empty($gpsResult['city'])) {
                $detectedCity = $gpsResult['city'];
                $detectedState = $gpsResult['state'];
                $confidence = $gpsResult['confidence'];
                $method = 'browser_geolocation';
                $localGeoResult = $gpsResult; // Use GPS coords for response
                Log::channel('detection')->info(
                    "GPS detection: {$detectedCity}, {$detectedState} " .
                    "(confidence: {$confidence}, distance: {$gpsResult['distance_km']}km)"
                );
            }
        }

        // STEP 4: LOCAL GEOIP — Only if no GPS result
        if (!$detectedCity && $this->localGeoIP->isAvailable()) {
            $localGeoResult = $this->localGeoIP->lookup($ip);
            if ($localGeoResult && !empty($localGeoResult['city'])) {
                $detectedCity = $localGeoResult['city'];
                $detectedState = $localGeoResult['state'];
                $confidence = $localGeoResult['confidence'];
                $method = 'local_geoip';
                Log::channel('detection')->info("Local GeoIP hit: {$detectedCity}, {$detectedState} (confidence: {$confidence})");
            }
        }

        // STEP 5: FALLBACK — Reverse DNS Parsing (only if no local GeoIP result)
        if (!$detectedCity) {
            $dnsResult = $this->reverseDNS->extractCity($hostname);
            if ($dnsResult) {
                $detectedCity = $dnsResult['city'];
                $detectedState = $dnsResult['state'];
                $confidence = $dnsResult['confidence'];
                $method = 'reverse_dns';
            }
        }

        // STEP 6: LAST RESORT — Ensemble IP APIs (only if still no result)
        if (!$detectedCity || $confidence < 70) {
            $ensembleResult = $this->ensembleIP->lookup($ip);
            $ensembleData = $ensembleResult['sources_data'] ?? null;

            if (!$detectedCity && $ensembleResult['city']) {
                $detectedCity = $ensembleResult['city'];
                $detectedState = $ensembleResult['state'];
                $confidence = $ensembleResult['confidence'];
                $method = 'ensemble_ip';
            } elseif (!$detectedCity && $ensembleResult['state']) {
                $detectedState = $ensembleResult['state'];
                $confidence = $ensembleResult['confidence'];
                $method = 'ensemble_ip';
            }
        }

        // Use learned data if nothing else worked
        if (!$detectedCity && $learnedResult) {
            $detectedCity = $learnedResult['city'];
            $detectedState = $learnedResult['state'];
            $confidence = $learnedResult['confidence'];
            $method = 'ip_range_learning';
        }

        // STEP 6: VPN Detection
        $asn = $ensembleResult['asn'] ?? null;
        $vpnResult = $this->vpnDetection->detect($ip, $asn, $hostname);

        if ($vpnResult['is_vpn']) {
            $penalty = config('detection.vpn_detection.confidence_penalty', 20);
            $confidence = max(0, $confidence - $penalty);
        }

        // STEP 7: Apply Fingerprint History
        if ($fingerprint && !$isNewUser && $fingerprint->visit_count >= config('detection.methods.fingerprint_history.min_visits', 3)) {
            if ($fingerprint->typical_city && $detectedCity && strtolower($fingerprint->typical_city) === strtolower($detectedCity)) {
                // Match — boost confidence
                $boost = config('detection.methods.fingerprint_history.confidence_boost', 15);
                $confidence = min(100, $confidence + $boost);
                if ($method !== 'reverse_dns') {
                    $method = 'fingerprint_history';
                }
            } elseif ($fingerprint->typical_city && $detectedCity && strtolower($fingerprint->typical_city) !== strtolower($detectedCity)) {
                // Location changed — could be travel or VPN
                $confidence = max(0, $confidence - 10);
            }
        }

        // Cap confidence
        $confidence = min(100, max(0, $confidence));

        // STEP 8: Determine final result
        $alternatives = [];
        $recommendation = null;

        if ($confidence < 55 || !$detectedCity) {
            $recommendation = 'soft_prompt';
            $alternatives = $ensembleResult['alternatives'] ?? [];
            if ($confidence < 55) {
                $detectedCity = null; // Only return state-level for low confidence
            }
        }

        // Parse browser/device info
        $browserInfo = $this->parseBrowserInfo($userAgent);

        // STEP 9: Save detection
        $processingTime = (int) ((microtime(true) - $startTime) * 1000);

        $detection = $this->saveDetection($client, [
            'fingerprint_id' => $fingerprintId ?? 'unknown',
            'session_id' => $signals['session_id'] ?? null,
            'detected_city' => $detectedCity,
            'detected_state' => $detectedState,
            'detected_country' => 'India',
            'confidence' => $confidence,
            'detection_method' => $method,
            'is_vpn' => $vpnResult['is_vpn'],
            'vpn_confidence' => $vpnResult['confidence'],
            'vpn_indicators' => $vpnResult['indicators'],
            'ip_address' => $ip,
            'reverse_dns' => $hostname,
            'isp' => $ensembleResult['isp'] ?? null,
            'asn' => $asn,
            'connection_type' => $ensembleResult['connection_type'] ?? 'unknown',
            'user_agent' => $userAgent,
            'browser' => $browserInfo['browser'],
            'os' => $browserInfo['os'],
            'device_type' => $browserInfo['device_type'],
            'timezone' => $signals['timezone'] ?? null,
            'language' => $signals['language'] ?? null,
            'ip_sources_data' => $ensembleData,
            'processing_time_ms' => $processingTime,
        ]);

        // STEP 10: Update fingerprint
        if ($fingerprintId) {
            $this->updateFingerprint($client, $fingerprintId, $detectedCity, $detectedState, $signals);
        }

        // STEP 11: Self-learning (async)
        if ($detection && $confidence >= config('detection.learning.min_confidence_to_learn', 80)) {
            LearnFromDetection::dispatch($detection);
        }

        // STEP 12: Build response
        return [
            'success' => true,
            'request_id' => $requestId,
            'user_id' => $fingerprintId ? "fp_{$fingerprintId}" : null,
            'is_new_user' => $isNewUser,
            'location' => [
                'city' => $detectedCity,
                'state' => $detectedState,
                'country' => 'India',
                'confidence' => $confidence,
                'method' => $method,
                'latitude' => $localGeoResult['latitude'] ?? $ensembleResult['latitude'] ?? null,
                'longitude' => $localGeoResult['longitude'] ?? $ensembleResult['longitude'] ?? null,
                'note' => $confidence < 55 ? 'Low confidence - state-level only' : null,
            ],
            'alternatives' => $recommendation ? $alternatives : [],
            'recommendation' => $recommendation,
            'vpn_detection' => [
                'is_vpn' => $vpnResult['is_vpn'],
                'confidence' => $vpnResult['confidence'],
                'indicators' => $vpnResult['indicators'],
            ],
            'user_history' => $fingerprint ? [
                'visit_count' => $fingerprint->visit_count,
                'first_seen' => $fingerprint->first_seen?->toIso8601String(),
                'last_seen' => $fingerprint->last_seen?->toIso8601String(),
                'trust_score' => $fingerprint->trust_score,
            ] : null,
            'processing_time_ms' => $processingTime,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    private function saveDetection(Client $client, array $data): ?UserDetection
    {
        try {
            return UserDetection::create(array_merge($data, [
                'client_id' => $client->id,
                'detected_at' => now(),
            ]));
        } catch (\Throwable $e) {
            Log::channel('detection')->error("Failed to save detection: {$e->getMessage()}");
            return null;
        }
    }

    private function updateFingerprint(Client $client, string $fingerprintId, ?string $city, ?string $state, array $signals): void
    {
        try {
            $fingerprint = UserFingerprint::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'fingerprint_id' => $fingerprintId,
                ],
                [
                    'first_seen' => now(),
                    'last_seen' => now(),
                    'visit_count' => 0,
                    'trust_score' => 50,
                    'typical_timezone' => $signals['timezone'] ?? null,
                    'typical_language' => $signals['language'] ?? null,
                ]
            );

            $fingerprint->incrementVisit();
            $fingerprint->updateTypicalLocation($city, $state);

            if ($city && $fingerprint->typical_city === $city) {
                $fingerprint->boostTrustScore(5);
            }
        } catch (\Throwable $e) {
            Log::channel('detection')->error("Failed to update fingerprint: {$e->getMessage()}");
        }
    }

    private function parseBrowserInfo(?string $userAgent): array
    {
        $browser = 'Unknown';
        $os = 'Unknown';
        $deviceType = 'desktop';

        if (!$userAgent) {
            return compact('browser', 'os', 'device_type');
        }

        // Browser detection
        if (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Edg/')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Safari')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'Opera') || str_contains($userAgent, 'OPR')) {
            $browser = 'Opera';
        }

        // OS detection
        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        }

        // Device type
        if (str_contains($userAgent, 'Mobile') || str_contains($userAgent, 'Android')) {
            $deviceType = 'mobile';
        } elseif (str_contains($userAgent, 'iPad') || str_contains($userAgent, 'Tablet')) {
            $deviceType = 'tablet';
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'device_type' => $deviceType,
        ];
    }

    private function isPrivateIP(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
