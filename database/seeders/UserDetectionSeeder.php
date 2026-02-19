<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\UserDetection;
use App\Models\UserFingerprint;
use Illuminate\Database\Seeder;

class UserDetectionSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::where('email', 'test@example.com')->first();
        if (!$client) return;

        $fingerprints = UserFingerprint::where('client_id', $client->id)->get();
        if ($fingerprints->isEmpty()) return;

        $cities = [
            ['city' => 'Ahmedabad', 'state' => 'Gujarat', 'weight' => 20],
            ['city' => 'Mumbai', 'state' => 'Maharashtra', 'weight' => 18],
            ['city' => 'Delhi', 'state' => 'Delhi', 'weight' => 15],
            ['city' => 'Bangalore', 'state' => 'Karnataka', 'weight' => 12],
            ['city' => 'Surat', 'state' => 'Gujarat', 'weight' => 10],
            ['city' => 'Pune', 'state' => 'Maharashtra', 'weight' => 8],
            ['city' => 'Chennai', 'state' => 'Tamil Nadu', 'weight' => 7],
            ['city' => 'Hyderabad', 'state' => 'Telangana', 'weight' => 5],
            ['city' => 'Kolkata', 'state' => 'West Bengal', 'weight' => 3],
            ['city' => 'Jaipur', 'state' => 'Rajasthan', 'weight' => 2],
        ];

        $methods = ['reverse_dns', 'ensemble_ip', 'fingerprint_history', 'ensemble_ip'];
        $browsers = ['Chrome', 'Firefox', 'Safari', 'Edge', 'Opera'];
        $oses = ['Windows', 'macOS', 'Linux', 'Android', 'iOS'];
        $deviceTypes = ['desktop', 'desktop', 'desktop', 'mobile', 'mobile', 'tablet'];
        $connectionTypes = ['broadband', 'broadband', 'broadband', 'mobile', 'broadband'];

        // Build weighted city pool
        $cityPool = [];
        foreach ($cities as $c) {
            for ($w = 0; $w < $c['weight']; $w++) {
                $cityPool[] = $c;
            }
        }

        $batch = [];

        for ($i = 0; $i < 300; $i++) {
            $fp = $fingerprints->random();
            $location = $cityPool[array_rand($cityPool)];
            $method = $methods[array_rand($methods)];
            $isVpn = rand(1, 100) <= 8; // 8% VPN rate
            $confidence = $this->generateConfidence($method, $isVpn);

            $detectedAt = now()->subDays(rand(0, 30))->subHours(rand(0, 23))->subMinutes(rand(0, 59));

            // Low confidence = no city
            $detectedCity = $confidence >= 60 ? $location['city'] : null;

            $batch[] = [
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'client_id' => $client->id,
                'fingerprint_id' => $fp->fingerprint_id,
                'session_id' => null,
                'detected_city' => $detectedCity,
                'detected_state' => $location['state'],
                'detected_country' => 'India',
                'confidence' => $confidence,
                'detection_method' => $method,
                'is_vpn' => $isVpn,
                'vpn_confidence' => $isVpn ? rand(60, 90) : rand(80, 100),
                'vpn_indicators' => $isVpn ? json_encode(['datacenter_asn']) : null,
                'ip_address' => $this->generateIndianIP(),
                'reverse_dns' => null,
                'isp' => ['Jio', 'Airtel', 'BSNL', 'Hathway', 'GTPL', 'ACT Fibernet'][rand(0, 5)],
                'asn' => ['AS55836', 'AS24560', 'AS9829', 'AS17762'][rand(0, 3)],
                'connection_type' => $connectionTypes[array_rand($connectionTypes)],
                'user_agent' => null,
                'browser' => $browsers[array_rand($browsers)],
                'os' => $oses[array_rand($oses)],
                'device_type' => $deviceTypes[array_rand($deviceTypes)],
                'timezone' => 'Asia/Kolkata',
                'language' => 'en-IN',
                'ip_sources_data' => null,
                'processing_time_ms' => rand(50, 800),
                'detected_at' => $detectedAt,
            ];

            // Insert in batches of 50
            if (count($batch) >= 50) {
                UserDetection::insert($batch);
                $batch = [];
            }
        }

        // Insert remaining
        if (!empty($batch)) {
            UserDetection::insert($batch);
        }
    }

    private function generateConfidence(string $method, bool $isVpn): int
    {
        $base = match ($method) {
            'reverse_dns' => rand(82, 92),
            'ensemble_ip' => rand(55, 85),
            'fingerprint_history' => rand(75, 95),
            default => rand(50, 75),
        };

        if ($isVpn) {
            $base = max(0, $base - rand(15, 25));
        }

        return $base;
    }

    private function generateIndianIP(): string
    {
        $prefixes = ['103', '106', '117', '122', '125', '182', '203', '49', '59', '61'];
        return $prefixes[array_rand($prefixes)] . '.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254);
    }
}
