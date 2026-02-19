<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('company_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('api_key', 64)->unique();
            $table->string('api_secret', 64);

            // Configuration
            $table->jsonb('allowed_domains')->nullable();
            $table->string('webhook_url', 500)->nullable();

            // Status
            $table->string('status', 20)->default('active')->index();
            $table->string('plan_type', 20)->default('free');

            // Timestamps
            $table->timestamps();
            $table->timestamp('last_api_call')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
