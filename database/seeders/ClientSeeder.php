<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // Update all existing live clients to admin plan
        $updated = Client::where('api_key', 'like', 'sk_live_%')
            ->update(['plan_type' => 'admin']);

        $this->command->info("Updated {$updated} live client(s) to admin plan.");

        // Test client with fixed API key for local/Postman testing
        $testClient = Client::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'company_name' => 'Test Company',
                'password' => Hash::make('test-password-123'),
                'api_key' => 'sk_test_localdev_1234567890abcdef1234567890abcdef12345678',
                'api_secret' => Hash::make('test-secret'),
                'allowed_domains' => null, // Allow all domains for testing
                'webhook_url' => null,
                'status' => 'active',
                'plan_type' => 'admin',
            ]
        );

        $this->command->info("Test client API key: {$testClient->api_key}");

        // Demo client with fixed API key
        $demoClient = Client::updateOrCreate(
            ['email' => 'demo@example.com'],
            [
                'company_name' => 'Demo Corp',
                'password' => Hash::make('demo-password-123'),
                'api_key' => 'sk_demo_localdev_1234567890abcdef1234567890abcdef12345678',
                'api_secret' => Hash::make('demo-secret'),
                'allowed_domains' => null,
                'webhook_url' => null,
                'status' => 'active',
                'plan_type' => 'starter',
            ]
        );

        $this->command->info("Demo client API key: {$demoClient->api_key}");
    }
}
