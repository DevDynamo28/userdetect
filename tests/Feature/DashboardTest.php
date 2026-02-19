<?php

namespace Tests\Feature;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'company_name' => 'Test Co',
            'email' => 'test@test.com',
            'password' => 'password',
            'api_key' => 'sk_test_' . Str::random(56),
            'api_secret' => Hash::make(Str::random(64)),
            'status' => 'active',
            'plan_type' => 'free',
        ]);
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/dashboard');

        $response->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200)
            ->assertSee('Sign in');
    }

    public function test_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'test@test.com',
            'password' => 'password',
        ]);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($this->client);
    }

    public function test_login_with_invalid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => 'test@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_dashboard_page_renders_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->client)->get('/dashboard');

        $response->assertStatus(200)
            ->assertSee('Dashboard')
            ->assertSee($this->client->company_name);
    }

    public function test_analytics_page_renders(): void
    {
        $response = $this->actingAs($this->client)->get('/dashboard/analytics');

        $response->assertStatus(200)
            ->assertSee('Analytics');
    }

    public function test_api_keys_page_renders(): void
    {
        $response = $this->actingAs($this->client)->get('/dashboard/api-keys');

        $response->assertStatus(200)
            ->assertSee('API Keys');
    }

    public function test_settings_page_renders(): void
    {
        $response = $this->actingAs($this->client)->get('/dashboard/settings');

        $response->assertStatus(200)
            ->assertSee('Settings');
    }

    public function test_docs_page_renders(): void
    {
        $response = $this->actingAs($this->client)->get('/dashboard/docs');

        $response->assertStatus(200)
            ->assertSee('Quick Start');
    }

    public function test_logout_works(): void
    {
        $response = $this->actingAs($this->client)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_settings_update(): void
    {
        $response = $this->actingAs($this->client)->put('/dashboard/settings', [
            'company_name' => 'Updated Company',
            'email' => 'test@test.com',
            'allowed_domains' => 'example.com, test.com',
        ]);

        $response->assertRedirect('/dashboard/settings');

        $this->client->refresh();
        $this->assertEquals('Updated Company', $this->client->company_name);
        $this->assertEquals(['example.com', 'test.com'], $this->client->allowed_domains);
    }

    public function test_api_key_regeneration(): void
    {
        $oldKey = $this->client->api_key;

        $response = $this->actingAs($this->client)->post('/dashboard/api-keys/regenerate');

        $response->assertRedirect('/dashboard/api-keys');

        $this->client->refresh();
        $this->assertNotEquals($oldKey, $this->client->api_key);
    }
}
