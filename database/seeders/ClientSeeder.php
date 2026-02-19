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
            // In production, only update existing client to admin
            $updated = Client::where('api_key', 'like', 'sk_live_%')
                ->update(['plan_type' => 'admin']);

            $this->command->info("Updated {$updated} live client(s) to admin plan.");
            return;
        }

        // Test client
        Client::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'company_name' => 'Test Company',
                'password' => Hash::make(Str::random(32)),
                'api_key' => 'sk_test_' . Str::random(56),
                'api_secret' => Hash::make(Str::random(64)),
                'allowed_domains' => ['localhost', '127.0.0.1'],
                'webhook_url' => null,
                'status' => 'active',
                'plan_type' => 'free',
            ]
        );

        // Demo client
        Client::updateOrCreate(
            ['email' => 'demo@example.com'],
            [
                'company_name' => 'Demo Corp',
                'password' => Hash::make(Str::random(32)),
                'api_key' => 'sk_demo_' . Str::random(56),
                'api_secret' => Hash::make(Str::random(64)),
                'allowed_domains' => null,
                'webhook_url' => null,
                'status' => 'active',
                'plan_type' => 'starter',
            ]
        );
    }
}
