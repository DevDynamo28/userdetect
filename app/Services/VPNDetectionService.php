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
     */
    public function detect(string $ip, ?string $asn, ?string $reverseDNS): array
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
