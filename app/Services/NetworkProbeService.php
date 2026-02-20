<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Network Probe Service — interprets browser-collected network signals.
 *
 * The JS SDK fetches Cloudflare's /cdn-cgi/trace endpoint from the BROWSER,
 * which tells us:
 *   1. Which CF PoP the browser's network path hits (independent of the PoP
 *      that our API Worker uses — the two routing paths often differ).
 *   2. The IP address Cloudflare sees from the browser (reveals split-tunnel VPN).
 *   3. Round-trip time from device to nearest CF PoP (refines city radius).
 *
 * Why this matters:
 *   - Standard IP GeoIP databases map ISP-registered city (often wrong for mobile).
 *   - This approach measures ACTUAL network topology — where the device IS, not
 *     where the ISP registered its IP.
 *   - A VPN user in India tunneling through Singapore will show colo=SIN, which
 *     is a strong VPN indicator without any paid intelligence API.
 */
class NetworkProbeService
{
    /**
     * CF PoP → primary city/state for India.
     *
     * Weight = fusion engine weight for state-level evidence from this colo.
     * City is only claimed when RTT ≤ RTT_CITY_THRESHOLD_MS.
     *
     * Candidate list = ordered probable cities within that PoP's coverage area,
     * used to override or challenge the GeoIP city when disagreement exists.
     */
    private const COLO_MAP = [
        // Tier-1 Indian PoPs (major metros — very reliable routing)
        'BOM' => ['state' => 'Maharashtra',    'city' => 'Mumbai',             'candidates' => ['Mumbai', 'Pune', 'Nashik', 'Aurangabad'],      'weight' => 38],
        'DEL' => ['state' => 'Delhi',          'city' => 'Delhi',              'candidates' => ['Delhi', 'Noida', 'Gurgaon', 'Faridabad'],      'weight' => 38],
        'BLR' => ['state' => 'Karnataka',      'city' => 'Bangalore',          'candidates' => ['Bangalore', 'Mysuru', 'Mangaluru', 'Hubli'],   'weight' => 38],
        'MAA' => ['state' => 'Tamil Nadu',     'city' => 'Chennai',            'candidates' => ['Chennai', 'Coimbatore', 'Madurai', 'Salem'],   'weight' => 35],
        'HYD' => ['state' => 'Telangana',      'city' => 'Hyderabad',          'candidates' => ['Hyderabad', 'Warangal', 'Vijayawada'],         'weight' => 35],
        'CCU' => ['state' => 'West Bengal',    'city' => 'Kolkata',            'candidates' => ['Kolkata', 'Bhubaneswar', 'Patna', 'Guwahati'], 'weight' => 35],
        // Tier-2 Indian PoPs
        'AMD' => ['state' => 'Gujarat',        'city' => 'Ahmedabad',          'candidates' => ['Ahmedabad', 'Surat', 'Vadodara', 'Rajkot'],    'weight' => 35],
        'COK' => ['state' => 'Kerala',         'city' => 'Kochi',              'candidates' => ['Kochi', 'Thiruvananthapuram', 'Kozhikode'],    'weight' => 33],
        'JAI' => ['state' => 'Rajasthan',      'city' => 'Jaipur',             'candidates' => ['Jaipur', 'Jodhpur', 'Udaipur', 'Kota'],       'weight' => 33],
        'IDR' => ['state' => 'Madhya Pradesh', 'city' => 'Indore',             'candidates' => ['Indore', 'Bhopal', 'Jabalpur'],               'weight' => 30],
        'PNQ' => ['state' => 'Maharashtra',    'city' => 'Pune',               'candidates' => ['Pune', 'Nashik', 'Kolhapur', 'Solapur'],      'weight' => 30],
        'NAG' => ['state' => 'Maharashtra',    'city' => 'Nagpur',             'candidates' => ['Nagpur', 'Amravati', 'Chandrapur'],           'weight' => 28],
        'IXC' => ['state' => 'Punjab',         'city' => 'Chandigarh',         'candidates' => ['Chandigarh', 'Amritsar', 'Ludhiana', 'Patiala'], 'weight' => 28],
        'LKO' => ['state' => 'Uttar Pradesh',  'city' => 'Lucknow',            'candidates' => ['Lucknow', 'Kanpur', 'Agra', 'Varanasi'],      'weight' => 28],
        'BBI' => ['state' => 'Odisha',         'city' => 'Bhubaneswar',        'candidates' => ['Bhubaneswar', 'Cuttack', 'Rourkela'],         'weight' => 25],
        'TRV' => ['state' => 'Kerala',         'city' => 'Thiruvananthapuram', 'candidates' => ['Thiruvananthapuram', 'Kochi', 'Kollam'],      'weight' => 25],
        'PAT' => ['state' => 'Bihar',          'city' => 'Patna',              'candidates' => ['Patna', 'Gaya', 'Muzaffarpur'],               'weight' => 25],
        'VNS' => ['state' => 'Uttar Pradesh',  'city' => 'Varanasi',           'candidates' => ['Varanasi', 'Prayagraj', 'Kanpur', 'Gorakhpur'], 'weight' => 22],
        'SXV' => ['state' => 'Tamil Nadu',     'city' => 'Salem',              'candidates' => ['Salem', 'Coimbatore', 'Madurai'],             'weight' => 20],
        'GAU' => ['state' => 'Assam',          'city' => 'Guwahati',           'candidates' => ['Guwahati', 'Shillong', 'Agartala'],           'weight' => 20],
        'ATQ' => ['state' => 'Punjab',         'city' => 'Amritsar',           'candidates' => ['Amritsar', 'Jalandhar', 'Ludhiana'],          'weight' => 20],
    ];

    /**
     * RTT ≤ this value from a CF PoP → user is in that PoP's primary city itself.
     * RTT ≤ STATE_THRESHOLD  → user is within the state (adjacent cities).
     * RTT > STATE_THRESHOLD  → coarser heuristic, state-level confidence only.
     *
     * Calibration: India fiber routing adds ~3× overhead over straight-line distance.
     * At 200,000 km/s raw speed → ~66,000 km/s effective → ~0.015ms/km.
     * 10ms = ~667km effective radius → captures adjacent cities within a state.
     * 30ms = ~2,000km effective radius → state-level only.
     */
    private const RTT_CITY_THRESHOLD_MS  = 10;
    private const RTT_STATE_THRESHOLD_MS = 30;

    /**
     * Process browser network probe signals into location evidence + VPN indicators.
     *
     * @param  array   $probes    signals.network_probes from the browser SDK
     * @param  string  $serverIp  CF-Connecting-IP (the IP our server received)
     * @return array{
     *   location_evidence: array|null,
     *   vpn_indicators: string[],
     *   candidates: string[],
     *   colo: string|null,
     *   rtt_ms: int|null,
     * }
     */
    public function process(array $probes, string $serverIp): array
    {
        $result = [
            'location_evidence' => null,
            'vpn_indicators'    => [],
            'candidates'        => [],
            'colo'              => null,
            'rtt_ms'            => null,
        ];

        $cfTrace = $probes['cf_trace'] ?? null;
        if (!is_array($cfTrace) || empty($cfTrace['colo'])) {
            return $result;
        }

        $colo    = strtoupper(trim((string) $cfTrace['colo']));
        $rttMs   = isset($cfTrace['rtt_ms']) ? (int) $cfTrace['rtt_ms'] : null;
        $traceIp = trim((string) ($cfTrace['ip'] ?? ''));

        $result['colo']   = $colo;
        $result['rtt_ms'] = $rttMs;

        // ──────────────────────────────────────────────────────────────────────
        // VPN Signal 1: Split-tunnel proxy detection
        // If the browser's CF trace reports a different IP than what the server
        // received, the user is running split-tunnel (some traffic bypasses VPN).
        // ──────────────────────────────────────────────────────────────────────
        if ($traceIp && $serverIp && $traceIp !== $serverIp
            && filter_var($traceIp, FILTER_VALIDATE_IP)
        ) {
            $result['vpn_indicators'][] = 'split_tunnel_proxy';
            Log::channel('detection')->info(
                "NetworkProbe: split-tunnel detected. server_ip={$serverIp}, browser_ip={$traceIp}"
            );
        }

        // ──────────────────────────────────────────────────────────────────────
        // VPN Signal 2: Browser hits a non-Indian CF PoP
        // A real Indian user's browser will almost always resolve to an Indian
        // CF PoP. SIN, FRA, LHR, etc. indicate a VPN exit node outside India.
        // ──────────────────────────────────────────────────────────────────────
        if (!isset(self::COLO_MAP[$colo])) {
            $result['vpn_indicators'][] = 'foreign_cf_colo';
            Log::channel('detection')->info(
                "NetworkProbe: browser CF trace colo={$colo} not in Indian PoP map — VPN/proxy likely"
            );
            return $result;
        }

        $coloData = self::COLO_MAP[$colo];

        // ──────────────────────────────────────────────────────────────────────
        // Location evidence: state is reliable from colo; city only when RTT
        // is very low (user is in the PoP's primary city itself).
        //
        // Key innovation for the Surat/Ahmedabad problem:
        //   AMD colo = user is in Gujarat (Ahmedabad, Surat, Vadodara, Rajkot…)
        //   If RTT to AMD PoP > 10ms → user is NOT in Ahmedabad city proper but
        //   in one of the surrounding Gujarat cities.
        //   This prevents blindly locking to "Ahmedabad" when the IP GeoIP is wrong.
        // ──────────────────────────────────────────────────────────────────────
        $cityConfidence  = 0;
        $stateConfidence = 0;
        $claimedCity     = null;

        if ($rttMs !== null) {
            if ($rttMs <= self::RTT_CITY_THRESHOLD_MS) {
                // Very close to the PoP city itself
                $claimedCity     = $coloData['city'];
                $cityConfidence  = 72;
                $stateConfidence = 88;
            } elseif ($rttMs <= self::RTT_STATE_THRESHOLD_MS) {
                // Within the state, but not in the primary city — use candidates
                $cityConfidence  = 0;
                $stateConfidence = 78;
            } else {
                // Too far for reliable city — state-level only
                $cityConfidence  = 0;
                $stateConfidence = 60;
            }
        } else {
            // No RTT data — colo gives state-level only
            $stateConfidence = 65;
        }

        $overallConfidence = $claimedCity ? max($cityConfidence, $stateConfidence) : $stateConfidence;

        $result['candidates']        = $coloData['candidates'];
        $result['location_evidence'] = [
            'source'     => 'network_probe',
            'city'       => $claimedCity,
            'state'      => $coloData['state'],
            'country'    => 'India',
            'confidence' => $overallConfidence,
            'weight'     => $coloData['weight'],
            'meta'       => [
                'colo'             => $colo,
                'rtt_ms'           => $rttMs,
                'candidates'       => $coloData['candidates'],
                'city_confidence'  => $cityConfidence,
                'state_confidence' => $stateConfidence,
            ],
        ];

        Log::channel('detection')->info(
            "NetworkProbe result: colo={$colo}, rtt={$rttMs}ms, " .
            "state={$coloData['state']}, claimed_city={$claimedCity}, " .
            "state_conf={$stateConfidence}, city_conf={$cityConfidence}"
        );

        return $result;
    }
}
