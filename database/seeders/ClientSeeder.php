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
        if (app()->environment('production')) {
            $this->command->warn('Skipping ClientSeeder in production environment.');
            return;
        }

        // Test client
        Client::create([
            'company_name' => 'Test Company',
            'email' => 'test@example.com',
            'password' => Hash::make(Str::random(32)),
            'api_key' => 'sk_test_' . Str::random(56),
            'api_secret' => Hash::make(Str::random(64)),
            'allowed_domains' => ['localhost', '127.0.0.1'],
            'webhook_url' => null,
            'status' => 'active',
            'plan_type' => 'free',
        ]);

        // Demo client
        Client::create([
            'company_name' => 'Demo Corp',
            'email' => 'demo@example.com',
            'password' => Hash::make(Str::random(32)),
            'api_key' => 'sk_demo_' . Str::random(56),
            'api_secret' => Hash::make(Str::random(64)),
            'allowed_domains' => null,
            'webhook_url' => null,
            'status' => 'active',
            'plan_type' => 'starter',
        ]);
    }
}
