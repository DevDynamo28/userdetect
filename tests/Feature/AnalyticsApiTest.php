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
        for ($i = 0; $i < 10; $i++) {
            UserDetection::create([
                'client_id' => $this->client->id,
                'fingerprint_id' => hash('sha256', "fp_{$i}"),
                'detected_city' => ['Ahmedabad', 'Mumbai', 'Delhi'][$i % 3],
                'detected_state' => ['Gujarat', 'Maharashtra', 'Delhi'][$i % 3],
                'confidence' => rand(60, 95),
                'detection_method' => ['reverse_dns', 'ensemble_ip'][$i % 2],
                'is_vpn' => $i === 0,
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
    }

    public function test_analytics_without_api_key_returns_401(): void
    {
        $response = $this->getJson('/api/v1/analytics/summary');

        $response->assertStatus(401);
    }
}
