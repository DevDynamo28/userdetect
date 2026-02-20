<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RDAP (Registration Data Access Protocol) Service.
 *
 * Queries APNIC/ARIN/RIPE RDAP for IP network registration data.
 * Standard GeoIP databases store a city for the ISP's registered block,
 * which is often the ISP's regional headquarters — not the user's city.
 * RDAP data comes directly from the IP allocation authority and often
 * contains ISP circle codes, network names, and remarks that narrow
 * down the geographic zone more accurately.
 *
 * For Indian ISPs:
 *   - Airtel RDAP records often contain circle names like "AIRTEL-GJ",
 *     "AIRTEL-MH", etc. — disambiguating Gujarat from Maharashtra even
 *     when the city-level GeoIP data is wrong.
 *   - Jio records use patterns like "RJIO-IN-AP" (Andhra Pradesh), etc.
 *   - BSNL records often have state-level allocation names.
 */
class RdapService
{
    private const CACHE_TTL   = 86400;  // 24 hours — allocation data changes rarely
    private const TIMEOUT_SEC = 3;

    /**
     * RDAP bootstrap: which registry handles which IP space.
     * APNIC covers all APAC IPs including India.
     */
    private const RDAP_ENDPOINTS = [
        'apnic' => 'https://rdap.apnic.net/ip/',
        'arin'  => 'https://rdap.arin.net/registry/ip/',
    ];

    /**
     * Regex patterns to extract ISP circle/zone from RDAP network name or remarks.
     * Each entry: [pattern, capture_index]
     *
     * Indian ISP patterns observed in RDAP:
     *   Airtel:  AIRTEL-GJ (Gujarat), AIRTEL-MH (Maharashtra), AIRTEL-KA (Karnataka)
     *   Jio:     RJIO-IN-GJ, RJIO-GJ
     *   BSNL:    BSNL-GJ, BSNLNET-GUJ
     *   Vi/Idea: IDEA-GJ, VODAFONE-GJ
     */
    private const ISP_CIRCLE_PATTERNS = [
        // Airtel: AIRTEL-{STATE_CODE} or ABTS-{CITY}-*
        '/AIRTEL[_-]([A-Z]{2,4})\b/i'   => ['type' => 'state_code'],
        '/ABTS[_-]([A-Z]{2,6})[_-]/i'   => ['type' => 'city_code'],

        // Jio: RJIO-IN-{STATE_CODE} or RJIO-{STATE_CODE}
        '/RJIO[_-](?:IN[_-])?([A-Z]{2,4})\b/i' => ['type' => 'state_code'],

        // BSNL: BSNL-{STATE_CODE} or BSNLNET-{STATE}
        '/BSNL(?:NET)?[_-]([A-Z]{2,6})\b/i'     => ['type' => 'state_code'],

        // Vodafone/Vi: VODAFONE-{STATE_CODE}, IDEA-{STATE_CODE}
        '/(?:VODAFONE|IDEA|VI)[_-]([A-Z]{2,4})\b/i' => ['type' => 'state_code'],

        // Generic ISP circle: ISP_NAME-IN-{STATECODE}
        '/\bIN[_-]([A-Z]{2,4})\b/i' => ['type' => 'state_code'],
    ];

    /**
     * Indian telecom circle/state code → state name mapping.
     * Sourced from TRAI's licensed service area codes.
     */
    private const CIRCLE_STATE_MAP = [
        // Standard 2-letter codes
        'GJ' => 'Gujarat',         'MH' => 'Maharashtra',
        'KA' => 'Karnataka',       'TN' => 'Tamil Nadu',
        'AP' => 'Andhra Pradesh',  'TS' => 'Telangana',   'TL' => 'Telangana',
        'WB' => 'West Bengal',     'DL' => 'Delhi',
        'UP' => 'Uttar Pradesh',   'MP' => 'Madhya Pradesh',
        'RJ' => 'Rajasthan',       'PB' => 'Punjab',
        'HR' => 'Haryana',         'KL' => 'Kerala',
        'OR' => 'Odisha',          'OD' => 'Odisha',      'BB' => 'Odisha',
        'AS' => 'Assam',           'BR' => 'Bihar',
        'JH' => 'Jharkhand',       'CT' => 'Chhattisgarh', 'CG' => 'Chhattisgarh',
        'UK' => 'Uttarakhand',     'GA' => 'Goa',
        'HP' => 'Himachal Pradesh','JK' => 'Jammu & Kashmir',
        'MN' => 'Manipur',        'ML' => 'Meghalaya',
        'TR' => 'Tripura',         'NE' => 'North East',
        // Longer RDAP codes used by some ISPs
        'GUJ' => 'Gujarat',        'MAH' => 'Maharashtra', 'MAR' => 'Maharashtra',
        'KAR' => 'Karnataka',      'TML' => 'Tamil Nadu',
        'AND' => 'Andhra Pradesh', 'TEL' => 'Telangana',
        'RAJ' => 'Rajasthan',      'PUN' => 'Punjab',
        'KER' => 'Kerala',
        // City-level codes from ABTS patterns
        'MUM' => null,  // Mumbai → resolve via city map below
        'DEL' => null,
        'AHM' => null, 'AMD' => null,
        'BLR' => null, 'BANG' => null,
        'CHE' => null, 'MAA' => null,
        'HYD' => null,
        'KOL' => null,
        'SRT' => null,   // Surat
        'VAD' => null,   // Vadodara
        'SURT' => null,
    ];

    /**
     * City code → canonical city name (for ABTS-{CITY} pattern in Airtel broadband).
     */
    private const CITY_CODE_MAP = [
        'MUM' => 'Mumbai',    'DEL' => 'Delhi',     'AHM' => 'Ahmedabad',
        'AMD' => 'Ahmedabad', 'BLR' => 'Bangalore', 'BANG' => 'Bangalore',
        'CHE' => 'Chennai',   'MAA' => 'Chennai',   'HYD' => 'Hyderabad',
        'KOL' => 'Kolkata',   'PUN' => 'Pune',      'JAI' => 'Jaipur',
        'SRT' => 'Surat',     'VAD' => 'Vadodara',  'SURT' => 'Surat',
        'RAJ' => 'Rajkot',    'LKO' => 'Lucknow',   'BPL' => 'Bhopal',
        'NAG' => 'Nagpur',    'IDR' => 'Indore',
    ];

    /**
     * Lookup IP in RDAP and extract ISP zone/circle info.
     *
     * @return array{
     *   state: string|null,
     *   city: string|null,
     *   network_name: string|null,
     *   isp_circle: string|null,
     *   confidence: int,
     *   source: string,
     * }|null
     */
    public function lookup(string $ip): ?array
    {
        $cacheKey = 'rdap:' . $ip;

        try {
            return Cache::remember($cacheKey, self::CACHE_TTL, fn() => $this->performLookup($ip));
        } catch (\Throwable $e) {
            Log::channel('detection')->warning("RDAP cache error for {$ip}: {$e->getMessage()}");
            return $this->performLookup($ip);
        }
    }

    private function performLookup(string $ip): ?array
    {
        foreach (self::RDAP_ENDPOINTS as $registry => $baseUrl) {
            try {
                $response = Http::timeout(self::TIMEOUT_SEC)
                    ->connectTimeout(2)
                    ->accept('application/rdap+json')
                    ->get($baseUrl . $ip);

                if (!$response->successful()) {
                    continue;
                }

                $data = $response->json();
                if (empty($data)) {
                    continue;
                }

                $result = $this->parse($data, $registry);
                if ($result) {
                    Log::channel('detection')->info(
                        "RDAP [{$registry}] for {$ip}: " .
                        "name={$result['network_name']}, circle={$result['isp_circle']}, " .
                        "state={$result['state']}, city={$result['city']}"
                    );
                    return $result;
                }

            } catch (\Throwable $e) {
                Log::channel('detection')->debug("RDAP [{$registry}] failed for {$ip}: {$e->getMessage()}");
            }
        }

        return null;
    }

    private function parse(array $data, string $registry): ?array
    {
        // Collect searchable strings from the RDAP object
        $name     = strtoupper(trim((string) ($data['name'] ?? '')));
        $handle   = strtoupper(trim((string) ($data['handle'] ?? '')));
        $type     = $data['type'] ?? '';

        // Gather remarks text
        $remarks = '';
        foreach ($data['remarks'] ?? [] as $remark) {
            foreach ($remark['description'] ?? [] as $line) {
                $remarks .= ' ' . strtoupper($line);
            }
        }

        // Combine all searchable text
        $searchText = $name . ' ' . $handle . ' ' . $remarks;

        [$state, $city, $circleCode] = $this->extractCircle($searchText, $name);

        if (!$state && !$city) {
            return null;
        }

        return [
            'state'        => $state,
            'city'         => $city,
            'network_name' => $name ?: null,
            'isp_circle'   => $circleCode,
            'confidence'   => $city ? 72 : 65,   // state-level from RDAP is ~65% reliable
            'source'       => "rdap_{$registry}",
        ];
    }

    /**
     * Extract telecom circle (state/city) from combined RDAP text.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null} [state, city, circleCode]
     */
    private function extractCircle(string $text, string $networkName): array
    {
        foreach (self::ISP_CIRCLE_PATTERNS as $pattern => $meta) {
            if (!preg_match($pattern, $text, $matches)) {
                continue;
            }

            $code = strtoupper(trim($matches[1]));

            if ($meta['type'] === 'city_code') {
                $city  = self::CITY_CODE_MAP[$code]  ?? null;
                $state = $city ? ($this->cityToState($city) ?? null) : null;
                if ($city || $state) {
                    return [$state, $city, $code];
                }
            }

            if ($meta['type'] === 'state_code') {
                $state = self::CIRCLE_STATE_MAP[$code] ?? null;
                if ($state) {
                    return [$state, null, $code];
                }

                // Could be a city code too — try both maps
                $city = self::CITY_CODE_MAP[$code] ?? null;
                if ($city) {
                    return [$this->cityToState($city), $city, $code];
                }
            }
        }

        return [null, null, null];
    }

    private function cityToState(string $city): ?string
    {
        $map = [
            'Mumbai' => 'Maharashtra', 'Pune' => 'Maharashtra', 'Nagpur' => 'Maharashtra',
            'Delhi' => 'Delhi', 'Noida' => 'Uttar Pradesh', 'Gurugram' => 'Haryana',
            'Ahmedabad' => 'Gujarat', 'Surat' => 'Gujarat', 'Vadodara' => 'Gujarat',
            'Rajkot' => 'Gujarat', 'Gandhinagar' => 'Gujarat',
            'Bangalore' => 'Karnataka', 'Chennai' => 'Tamil Nadu',
            'Kolkata' => 'West Bengal', 'Hyderabad' => 'Telangana',
            'Jaipur' => 'Rajasthan', 'Lucknow' => 'Uttar Pradesh',
            'Bhopal' => 'Madhya Pradesh', 'Indore' => 'Madhya Pradesh',
        ];
        return $map[$city] ?? null;
    }
}
