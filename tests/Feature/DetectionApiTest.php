<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DetectionApiTest extends TestCase
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
    }

    public function test_detect_with_valid_api_key_returns_success(): void
    {
        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'fingerprint' => hash('sha256', 'test_fingerprint'),
                'timezone' => 'Asia/Kolkata',
                'language' => 'en-IN',
                'user_agent' => 'Mozilla/5.0 Test',
            ],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'request_id',
                'user_id',
                'is_new_user',
                'location' => ['city', 'state', 'country', 'confidence', 'method'],
                'vpn_detection' => ['is_vpn', 'confidence', 'indicators'],
                'timestamp',
            ])
            ->assertJson(['success' => true]);
    }

    public function test_detect_without_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'fingerprint' => hash('sha256', 'test'),
            ],
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'MISSING_API_KEY'],
            ]);
    }

    public function test_detect_with_invalid_api_key_returns_401(): void
    {
        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'fingerprint' => hash('sha256', 'test'),
            ],
        ], [
            'X-API-Key' => 'invalid_key_here',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'INVALID_API_KEY'],
            ]);
    }

    public function test_detect_without_signals_returns_422(): void
    {
        $response = $this->postJson('/api/v1/detect', [], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR'],
            ]);
    }

    public function test_detect_without_fingerprint_returns_422(): void
    {
        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'timezone' => 'Asia/Kolkata',
            ],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(422);
    }

    public function test_detect_marks_new_user_correctly(): void
    {
        $fingerprint = hash('sha256', 'new_user_test');

        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'fingerprint' => $fingerprint,
                'timezone' => 'Asia/Kolkata',
            ],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJson(['is_new_user' => true]);
    }

    public function test_inactive_client_returns_401(): void
    {
        $this->client->update(['status' => 'suspended']);

        $response = $this->postJson('/api/v1/detect', [
            'signals' => [
                'fingerprint' => hash('sha256', 'test'),
            ],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(401);
    }
}
