<?php

namespace Tests\Feature;

use App\Jobs\LearnFromDetection;
use App\Models\Client;
use App\Models\UserDetection;
use App\Models\UserFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = 'sk_test_' . Str::random(56);
        $this->client = Client::create([
            'company_name' => 'Verify Test Co',
            'email' => 'verify@test.com',
            'password' => 'password',
            'api_key' => $this->apiKey,
            'api_secret' => Hash::make(Str::random(64)),
            'status' => 'active',
            'plan_type' => 'free',
        ]);
    }

    public function test_verify_location_requires_api_key(): void
    {
        $fingerprintId = hash('sha256', 'verify_auth_test');

        $response = $this->postJson("/api/v1/user/{$fingerprintId}/verify-location", [
            'city' => 'Boston',
            'state' => 'MA',
            'country' => 'United States',
            'source' => 'checkout',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'MISSING_API_KEY'],
            ]);
    }

    public function test_verify_location_validates_payload(): void
    {
        $fingerprintId = hash('sha256', 'verify_validation_test');

        $response = $this->postJson("/api/v1/user/{$fingerprintId}/verify-location", [
            'city' => '',
            'source' => 'invalid_source',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'error' => ['code' => 'VALIDATION_ERROR'],
            ]);
    }

    public function test_verify_location_backfills_recent_detections_and_dispatches_learning_jobs(): void
    {
        Queue::fake();

        $fingerprintId = hash('sha256', 'verify_backfill_test');

        UserFingerprint::create([
            'client_id' => $this->client->id,
            'fingerprint_id' => $fingerprintId,
            'first_seen' => now()->subDays(7),
            'last_seen' => now()->subHours(2),
            'visit_count' => 6,
            'trust_score' => 50,
            'typical_country' => 'United States',
            'city_visit_counts' => [],
            'state_visit_counts' => [],
        ]);

        $highConfidenceMatch = $this->createDetection([
            'fingerprint_id' => $fingerprintId,
            'detected_city' => 'Boston',
            'detected_state' => 'MA',
            'detected_country' => 'United States',
            'confidence' => 92,
            'detection_method' => 'reverse_dns',
            'detected_at' => now()->subHours(1),
        ]);

        $lowConfidenceMatch = $this->createDetection([
            'fingerprint_id' => $fingerprintId,
            'detected_city' => 'Boston',
            'detected_state' => 'MA',
            'detected_country' => 'United States',
            'confidence' => 64,
            'detection_method' => 'ensemble_ip',
            'detected_at' => now()->subHours(3),
        ]);

        $mismatchDetection = $this->createDetection([
            'fingerprint_id' => $fingerprintId,
            'detected_city' => 'Cambridge',
            'detected_state' => 'MA',
            'detected_country' => 'United States',
            'confidence' => 86,
            'detection_method' => 'ensemble_ip',
            'detected_at' => now()->subHours(4),
        ]);

        $outsideWindow = $this->createDetection([
            'fingerprint_id' => $fingerprintId,
            'detected_city' => 'Boston',
            'detected_state' => 'MA',
            'detected_country' => 'United States',
            'confidence' => 91,
            'detection_method' => 'reverse_dns',
            'detected_at' => now()->subHours(240),
        ]);

        $response = $this->postJson("/api/v1/user/{$fingerprintId}/verify-location", [
            'city' => 'Boston',
            'state' => 'MA',
            'country' => 'United States',
            'source' => 'checkout',
            'backfill_hours' => 72,
            'max_records' => 50,
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.annotated_records', 3)
            ->assertJsonPath('data.results.matched_records', 2)
            ->assertJsonPath('data.results.mismatched_records', 1)
            ->assertJsonPath('data.results.match_rate', 66.7)
            ->assertJsonPath('data.results.learning_jobs_dispatched', 1);

        $highConfidenceMatch->refresh();
        $lowConfidenceMatch->refresh();
        $mismatchDetection->refresh();
        $outsideWindow->refresh();

        $this->assertTrue($highConfidenceMatch->is_location_verified);
        $this->assertTrue($lowConfidenceMatch->is_location_verified);
        $this->assertFalse($mismatchDetection->is_location_verified);
        $this->assertSame('checkout', $highConfidenceMatch->verification_source);
        $this->assertSame('Boston', $highConfidenceMatch->verified_city);
        $this->assertNull($outsideWindow->verified_city);

        Queue::assertPushed(LearnFromDetection::class, 1);

        $fingerprint = UserFingerprint::query()
            ->where('client_id', $this->client->id)
            ->where('fingerprint_id', $fingerprintId)
            ->firstOrFail();

        $this->assertSame('Boston', $fingerprint->typical_city);
        $this->assertSame('MA', $fingerprint->typical_state);
        $this->assertSame(53, $fingerprint->trust_score);
    }

    public function test_verify_location_returns_empty_summary_when_no_recent_detections(): void
    {
        $fingerprintId = hash('sha256', 'verify_empty_test');

        $response = $this->postJson("/api/v1/user/{$fingerprintId}/verify-location", [
            'city' => 'Boston',
            'state' => 'MA',
            'country' => 'United States',
            'source' => 'manual',
            'backfill_hours' => 24,
            'max_records' => 100,
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results.annotated_records', 0)
            ->assertJsonPath('data.results.matched_records', 0)
            ->assertJsonPath('data.results.mismatched_records', 0)
            ->assertJsonPath('data.results.match_rate', 0)
            ->assertJsonPath('data.results.learning_jobs_dispatched', 0);
    }

    private function createDetection(array $overrides = []): UserDetection
    {
        $defaults = [
            'client_id' => $this->client->id,
            'fingerprint_id' => hash('sha256', 'default_fp'),
            'detected_city' => 'Boston',
            'detected_state' => 'MA',
            'detected_country' => 'United States',
            'confidence' => 85,
            'detection_method' => 'reverse_dns',
            'is_vpn' => false,
            'vpn_confidence' => 0,
            'ip_address' => '198.51.100.10',
            'detected_at' => now()->subHour(),
        ];

        return UserDetection::create(array_merge($defaults, $overrides));
    }
}
