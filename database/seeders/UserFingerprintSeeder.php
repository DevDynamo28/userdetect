<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\UserFingerprint;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class UserFingerprintSeeder extends Seeder
{
    public function run(): void
    {
        $client = Client::where('email', 'test@example.com')->first();
        if (!$client) return;

        $cities = [
            ['city' => 'Ahmedabad', 'state' => 'Gujarat'],
            ['city' => 'Mumbai', 'state' => 'Maharashtra'],
            ['city' => 'Delhi', 'state' => 'Delhi'],
            ['city' => 'Bangalore', 'state' => 'Karnataka'],
            ['city' => 'Chennai', 'state' => 'Tamil Nadu'],
            ['city' => 'Kolkata', 'state' => 'West Bengal'],
            ['city' => 'Hyderabad', 'state' => 'Telangana'],
            ['city' => 'Pune', 'state' => 'Maharashtra'],
            ['city' => 'Surat', 'state' => 'Gujarat'],
            ['city' => 'Jaipur', 'state' => 'Rajasthan'],
        ];

        for ($i = 0; $i < 30; $i++) {
            $location = $cities[array_rand($cities)];
            $visitCount = rand(1, 50);
            $trustScore = min(100, 50 + ($visitCount * 2));

            $cityVisits = [$location['city'] => $visitCount];
            if (rand(0, 3) === 0) {
                $other = $cities[array_rand($cities)];
                $cityVisits[$other['city']] = rand(1, 5);
            }

            $stateVisits = [$location['state'] => array_sum($cityVisits)];

            UserFingerprint::create([
                'client_id' => $client->id,
                'fingerprint_id' => hash('sha256', 'seed_fp_' . $i . '_' . Str::random(16)),
                'first_seen' => now()->subDays(rand(7, 60)),
                'last_seen' => now()->subHours(rand(0, 72)),
                'visit_count' => $visitCount,
                'typical_city' => $location['city'],
                'typical_state' => $location['state'],
                'typical_country' => 'India',
                'city_visit_counts' => $cityVisits,
                'state_visit_counts' => $stateVisits,
                'trust_score' => $trustScore,
                'typical_timezone' => 'Asia/Kolkata',
                'typical_language' => ['en-IN', 'hi', 'gu-IN', 'ta', 'te', 'bn'][rand(0, 5)],
            ]);
        }
    }
}
