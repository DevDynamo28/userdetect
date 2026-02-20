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
        private SignalFusionService $signalFusion,
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
        $defaultCountry = config('detection.default_country', 'India');

        // STEP 1: Get IP and basic info
        $ip = $this->resolveClientIp($request);
        $signals = $request->input('signals', []);
        $verification = $this->extractLocationVerification($signals);
        $fingerprintId = $signals['fingerprint'] ?? null;
        $userAgent = $signals['user_agent'] ?? $request->userAgent();

        // Allow IP override via X-Test-IP header for testing
        // Works in local/testing env, or in production only for admin clients
        $testIp = $request->header('X-Test-IP');
        if ($testIp && filter_var($testIp, FILTER_VALIDATE_IP)) {
            $allowOverride = app()->environment('local', 'testing')
                || $client->plan_type === 'admin';
            if ($allowOverride) {
                $ip = $testIp;
                Log::channel('detection')->info("Using test IP override: {$ip}");
            }
        } elseif (app()->environment('local', 'testing') && $this->isPrivateIP($ip)) {
            $envTestIp = config('detection.test_ip');
            if ($envTestIp) {
                $ip = $envTestIp;
                Log::channel('detection')->info("Using env test IP: {$ip}");
            }
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
        $learnedResult = $this->learning->checkIPRangeLearnings($client->id, $ip);

        // STEP 4: SIGNAL FUSION — combine ALL passive signals
        $fusionResult = $this->signalFusion->inferLocation($ip, $signals, $request);

        $detectedCity = $fusionResult['city'];
        $detectedState = $fusionResult['state'];
        $detectedCountry = $fusionResult['country'] ?? $defaultCountry;
        $confidence = $fusionResult['confidence'];
        $method = $fusionResult['method'];
        $ensembleData = $fusionResult['sources_data'] ?? null;
        $qualityTelemetry = $fusionResult['quality_telemetry'] ?? [];

        // Use learned data if fusion didn't produce a city
        if (!$detectedCity && $learnedResult) {
            $detectedCity = $learnedResult['city'];
            $detectedState = $learnedResult['state'];
            $detectedCountry = $defaultCountry;
            $confidence = $learnedResult['confidence'];
            $method = 'ip_range_learning';
        }

        // STEP 5: VPN Detection
        $asn = $fusionResult['asn'] ?? null;
        $hostname = $fusionResult['reverse_dns_hostname'] ?? null;
        if (!$hostname) {
            try {
                $hostname = gethostbyaddr($ip);
                if ($hostname === $ip)
                    $hostname = null;
            } catch (\Throwable $e) {
            }
        }

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
            $alternatives = $fusionResult['alternatives'] ?? [];
            if ($confidence < 55) {
                $detectedCity = null; // Only return state-level for low confidence
            }
        }

        $isLocationVerified = $this->isLocationVerificationMatch($verification, $detectedCity, $detectedState);

        // Parse browser/device info
        $browserInfo = $this->parseBrowserInfo($userAgent);

        // STEP 9: Save detection
        $processingTime = (int) ((microtime(true) - $startTime) * 1000);
        $targetP95Ms = (int) config('detection.slo.detect_p95_ms', 700);
        if ($processingTime > $targetP95Ms) {
            Log::channel('detection')->warning('Detection exceeded latency SLO target', [
                'processing_time_ms' => $processingTime,
                'target_p95_ms' => $targetP95Ms,
                'method' => $method,
            ]);
        }

        $detection = null;

        DB::transaction(function () use (
            $client, $fingerprintId, $signals, $detectedCity, $detectedState,
            $confidence, $method, $vpnResult, $ip, $hostname, $fusionResult,
            $asn, $ensembleData, $userAgent, $browserInfo, $processingTime,
            $detectedCountry, $isLocationVerified, $verification,
            &$detection
        ) {
            $detection = $this->saveDetection($client, [
                'fingerprint_id' => $fingerprintId ?? 'unknown',
                'session_id' => $signals['session_id'] ?? null,
                'detected_city' => $detectedCity,
                'detected_state' => $detectedState,
                'detected_country' => $detectedCountry,
                'confidence' => $confidence,
                'detection_method' => $method,
                'is_vpn' => $vpnResult['is_vpn'],
                'vpn_confidence' => $vpnResult['confidence'],
                'vpn_indicators' => $vpnResult['indicators'],
                'ip_address' => $ip,
                'reverse_dns' => $hostname,
                'isp' => $fusionResult['isp'] ?? null,
                'asn' => $asn,
                'connection_type' => $fusionResult['connection_type'] ?? 'unknown',
                'user_agent' => $userAgent,
                'browser' => $browserInfo['browser'],
                'os' => $browserInfo['os'],
                'device_type' => $browserInfo['device_type'],
                'timezone' => $signals['timezone'] ?? null,
                'language' => $signals['language'] ?? null,
                'ip_sources_data' => $ensembleData,
                'verified_city' => $verification['city'] ?? null,
                'verified_state' => $verification['state'] ?? null,
                'verified_country' => $verification['country'] ?? null,
                'is_location_verified' => $isLocationVerified,
                'verification_source' => $verification['source'] ?? null,
                'verification_received_at' => !empty($verification['city']) ? now() : null,
                'state_disagreement_count' => (int) ($qualityTelemetry['state_disagreement_count'] ?? 0),
                'city_disagreement_count' => (int) ($qualityTelemetry['city_disagreement_count'] ?? 0),
                'fallback_reason' => $qualityTelemetry['fallback_reason'] ?? null,
                'processing_time_ms' => $processingTime,
            ]);

            // Update fingerprint within the same transaction
            if ($fingerprintId) {
                $this->updateFingerprint($client, $fingerprintId, $detectedCity, $detectedState, $signals);
            }
        });

        // Self-learning (async, outside transaction)
        $learningRequiresVerified = config('detection.learning.require_verified_location', true);
        if (
            $detection
            && $confidence >= config('detection.learning.min_confidence_to_learn', 80)
            && (!$learningRequiresVerified || $isLocationVerified)
        ) {
            LearnFromDetection::dispatch($detection);
        }

        // STEP 12: Build response
        $response = [
            'success' => true,
            'request_id' => $requestId,
            'user_id' => $fingerprintId ? "fp_{$fingerprintId}" : null,
            'is_new_user' => $isNewUser,
            'location' => [
                'city' => $detectedCity,
                'state' => $detectedState,
                'country' => $detectedCountry,
                'confidence' => $confidence,
                'method' => $method,
                'latitude' => $fusionResult['latitude'] ?? null,
                'longitude' => $fusionResult['longitude'] ?? null,
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

        $includeDebugInfo = (bool) data_get($request->input('options', []), 'include_debug_info', false);
        if ($includeDebugInfo) {
            $response['diagnostics'] = [
                'quality_telemetry' => $fusionResult['quality_telemetry'] ?? null,
                'fusion_debug' => $fusionResult['fusion_debug'] ?? null,
                'slo_target_ms' => $targetP95Ms,
                'slo_breached' => $processingTime > $targetP95Ms,
            ];
        }

        return $response;
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
            return [
                'browser' => $browser,
                'os' => $os,
                'device_type' => $deviceType,
            ];
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

    /**
     * Resolve end-user IP from trusted edge headers without relying on browser permissions.
     */
    private function resolveClientIp(Request $request): string
    {
        $fallback = $request->ip();

        $candidates = [];
        $forwardedByWorker = $request->header('X-Worker-Forwarded') === '1';
        if ($forwardedByWorker) {
            $candidates[] = $request->header('CF-Connecting-IP');
            $candidates[] = $request->header('X-Real-IP');
        }

        $candidates[] = $fallback;

        foreach ($candidates as $candidate) {
            if (!$candidate || !filter_var($candidate, FILTER_VALIDATE_IP)) {
                continue;
            }

            return $candidate;
        }

        return $fallback;
    }

    private function extractLocationVerification(array $signals): array
    {
        $verification = $signals['location_verification'] ?? null;
        if (!is_array($verification)) {
            return ['city' => null, 'state' => null, 'country' => null, 'source' => null];
        }

        $city = trim((string) ($verification['city'] ?? ''));
        $state = trim((string) ($verification['state'] ?? ''));
        $country = trim((string) ($verification['country'] ?? config('detection.default_country', 'India')));
        $source = trim((string) ($verification['source'] ?? ''));

        if ($city === '' || $source === '') {
            return ['city' => null, 'state' => null, 'country' => null, 'source' => null];
        }

        return [
            'city' => $city,
            'state' => $state !== '' ? $state : null,
            'country' => $country !== '' ? $country : null,
            'source' => $source,
        ];
    }

    private function isLocationVerificationMatch(array $verification, ?string $detectedCity, ?string $detectedState): bool
    {
        if (!$detectedCity || empty($verification['city'])) {
            return false;
        }

        if (strcasecmp($verification['city'], $detectedCity) !== 0) {
            return false;
        }

        if (!empty($verification['state']) && !empty($detectedState)) {
            return strcasecmp($verification['state'], $detectedState) === 0;
        }

        return true;
    }
}
