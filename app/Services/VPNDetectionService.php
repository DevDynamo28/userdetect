<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class VPNDetectionService
{
    private array $datacenterAsns;

    private array $vpnHostnameKeywords = [
        'vpn', 'proxy', 'tunnel', 'relay', 'node', 'exit',
        'tor', 'anon', 'anonymous', 'privacy', 'hide', 'mask',
    ];

    private array $hostingKeywords = [
        'amazon', 'amazonaws', 'digitalocean', 'ovh', 'linode',
        'vultr', 'hetzner', 'cloudflare', 'azure', 'googlecloud',
        'gcloud', 'rackspace', 'softlayer', 'choopa', 'contabo',
    ];

    public function __construct()
    {
        $this->datacenterAsns = config('detection.vpn_detection.datacenter_asns', []);
    }

    /**
     * Detect if the IP is likely a VPN/proxy.
     *
     * @param  string|null  $cfAsOrg           Cloudflare AS Organization (X-CF-ASOrg header).
     *                                         CF labels known VPN providers here, e.g. "VPN-Consumer-IN".
     * @param  string[]     $probeVpnIndicators VPN signals from the browser network probe
     *                                         (e.g. 'foreign_cf_colo', 'split_tunnel_proxy').
     */
    public function detect(string $ip, ?string $asn, ?string $reverseDNS, ?string $cfAsOrg = null, array $probeVpnIndicators = []): array
    {
        $vpnScore = 0;
        $indicators = [];

        // Check 1: Datacenter ASN (+40)
        if ($asn && $this->isDatacenterAsn($asn)) {
            $vpnScore += 40;
            $indicators[] = 'datacenter_asn';
        }

        // Check 2: VPN keywords in hostname (+50)
        // Explicit VPN/proxy naming in reverse DNS is a strong indicator.
        if ($reverseDNS && $this->hasVpnKeywords($reverseDNS)) {
            $vpnScore += 50;
            $indicators[] = 'vpn_hostname';
        }

        // Check 3: Hosting provider keywords (+25)
        if ($this->isHostingProvider($reverseDNS, $asn)) {
            $vpnScore += 25;
            $indicators[] = 'hosting_provider';
        }

        // Check 4: Private IP ranges — skip in local environment, flag in production
        if ($this->isPrivateOrReserved($ip) && !app()->environment('local', 'testing')) {
            $vpnScore += 20;
            $indicators[] = 'suspicious_ip_range';
        }

        // Check 5: CF AS Organization contains VPN/proxy keywords (+60, very strong signal).
        // Cloudflare labels known VPN providers directly in asOrganization, e.g. "VPN-Consumer-IN".
        // This is the most reliable indicator available without a paid IP intelligence API.
        if ($cfAsOrg && $this->hasVpnKeywords($cfAsOrg)) {
            $vpnScore += 60;
            $indicators[] = 'vpn_organization';
        }

        // Check 6: CF AS Organization is a known hosting/datacenter provider (+25).
        // Only add if hosting_provider was not already flagged via hostname/asn.
        if ($cfAsOrg && !in_array('hosting_provider', $indicators, true) && $this->isHostingProvider($cfAsOrg, null)) {
            $vpnScore += 25;
            $indicators[] = 'hosting_provider';
        }

        // Check 7: Browser network probe indicators.
        // foreign_cf_colo  — browser's CF trace hit a non-Indian PoP (e.g. SIN, FRA, LHR).
        //                    For a user who should be in India this is a strong VPN signal (+55).
        // split_tunnel_proxy — IP seen by CF in browser trace differs from CF-Connecting-IP (+45).
        //                    Means some traffic bypasses the VPN (split tunnel).
        foreach ($probeVpnIndicators as $indicator) {
            if (in_array($indicator, $indicators, true)) {
                continue; // already counted
            }
            $score = match ($indicator) {
                'foreign_cf_colo'    => 55,
                'split_tunnel_proxy' => 45,
                default              => 0,
            };
            if ($score > 0) {
                $vpnScore     += $score;
                $indicators[] = $indicator;
            }
        }

        // Calculate result
        $isVpn = $vpnScore >= 50;
        $confidence = $isVpn ? min(95, $vpnScore) : max(5, 100 - $vpnScore);

        if ($isVpn) {
            Log::channel('detection')->info("VPN detected for IP {$ip}: score={$vpnScore}, indicators=" . implode(',', $indicators));
        }

        return [
            'is_vpn' => $isVpn,
            'confidence' => $confidence,
            'vpn_score' => $vpnScore,
            'indicators' => $indicators,
        ];
    }

    private function isDatacenterAsn(?string $asn): bool
    {
        if (!$asn) {
            return false;
        }

        // Normalize ASN format
        $normalized = strtoupper(trim($asn));
        if (!str_starts_with($normalized, 'AS')) {
            $normalized = 'AS' . $normalized;
        }

        return in_array($normalized, $this->datacenterAsns);
    }

    private function hasVpnKeywords(?string $hostname): bool
    {
        if (!$hostname) {
            return false;
        }

        $lower = strtolower($hostname);

        foreach ($this->vpnHostnameKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isHostingProvider(?string $hostname, ?string $asn): bool
    {
        $searchString = strtolower(($hostname ?? '') . ' ' . ($asn ?? ''));

        foreach ($this->hostingKeywords as $keyword) {
            if (str_contains($searchString, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function isPrivateOrReserved(string $ip): bool
    {
        return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}
