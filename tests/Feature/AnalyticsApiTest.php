<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\UserDetection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = 'sk_test_' . Str::random(56);
        $this->client = Client::create([
            'company_name' => 'Test Co',
            'email' => 'test@test.com',
            'password' => 'password',
            'api_key' => $this->apiKey,
            'api_secret' => Hash::make(Str::random(64)),
            'status' => 'active',
            'plan_type' => 'free',
        ]);

        // Seed some detections
        $confidences = [90, 82, 54, 40, 76, 88, 52, 61, 49, 95];
        for ($i = 0; $i < 10; $i++) {
            $detectedCity = ['Ahmedabad', 'Mumbai', 'Delhi'][$i % 3];
            $verifiedCity = $i < 4
                ? ($i === 2 ? 'Pune' : $detectedCity)
                : null;
            $isVerified = $verifiedCity !== null && strcasecmp($verifiedCity, $detectedCity) === 0;

            UserDetection::create([
                'client_id' => $this->client->id,
                'fingerprint_id' => hash('sha256', "fp_{$i}"),
                'detected_city' => $detectedCity,
                'detected_state' => ['Gujarat', 'Maharashtra', 'Delhi'][$i % 3],
                'confidence' => $confidences[$i],
                'detection_method' => ['reverse_dns', 'ensemble_ip'][$i % 2],
                'is_vpn' => $i === 0,
                'city_disagreement_count' => in_array($i, [0, 5], true) ? 1 : 0,
                'state_disagreement_count' => in_array($i, [4, 8], true) ? 1 : 0,
                'verified_city' => $verifiedCity,
                'verified_state' => $verifiedCity ? ['Gujarat', 'Maharashtra', 'Delhi'][$i % 3] : null,
                'verified_country' => $verifiedCity ? 'India' : null,
                'is_location_verified' => $isVerified,
                'verification_source' => $verifiedCity ? 'checkout' : null,
                'verification_received_at' => $verifiedCity ? now()->subMinutes(5) : null,
                'ip_address' => '103.0.0.' . ($i + 1),
                'detected_at' => now()->subHours($i),
            ]);
        }
    }

    public function test_analytics_summary_returns_correct_structure(): void
    {
        $response = $this->getJson('/api/v1/analytics/summary?period=last_7_days', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'period',
                'usage' => ['total_requests', 'unique_users', 'average_confidence'],
                'top_cities',
                'detection_methods',
                'vpn_stats' => ['total_vpn_detected', 'percentage'],
                'confidence_distribution',
                'quality_metrics' => [
                    'city_hit_rate',
                    'city_hit_rate_by_method',
                    'low_confidence_count',
                    'low_confidence_rate',
                    'disagreement_count',
                    'disagreement_rate',
                    'disagreement_rate_by_method',
                    'verified_label_total',
                    'verified_label_matches',
                    'verified_label_mismatches',
                    'verified_label_coverage_rate',
                    'verified_label_accuracy_rate',
                    'verified_accuracy_by_method',
                ],
            ])
            ->assertJson([
                'success' => true,
                'period' => 'last_7_days',
            ]);
    }

    public function test_analytics_summary_counts_are_correct(): void
    {
        $response = $this->getJson('/api/v1/analytics/summary?period=last_30_days', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200);

        $data = $response->json();
        $this->assertEquals(10, $data['usage']['total_requests']);
        $this->assertEquals(1, $data['vpn_stats']['total_vpn_detected']);
        $this->assertEquals(100.0, $data['quality_metrics']['city_hit_rate']);
        $this->assertEquals(4, $data['quality_metrics']['low_confidence_count']);
        $this->assertEquals(4, $data['quality_metrics']['disagreement_count']);
        $this->assertEquals(4, $data['quality_metrics']['verified_label_total']);
        $this->assertEquals(3, $data['quality_metrics']['verified_label_matches']);
        $this->assertEquals(1, $data['quality_metrics']['verified_label_mismatches']);
        $this->assertEquals(75.0, $data['quality_metrics']['verified_label_accuracy_rate']);
    }

    public function test_analytics_without_api_key_returns_401(): void
    {
        $response = $this->getJson('/api/v1/analytics/summary');

        $response->assertStatus(401);
    }
}
