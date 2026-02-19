<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_fingerprints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('fingerprint_id', 64);

            // Visit tracking
            $table->timestamp('first_seen')->useCurrent();
            $table->timestamp('last_seen')->useCurrent();
            $table->integer('visit_count')->default(1);

            // Learned patterns
            $table->string('typical_city', 100)->nullable();
            $table->string('typical_state', 100)->nullable();
            $table->string('typical_country', 100)->default('India');

            // Visit distribution (JSONB)
            $table->jsonb('city_visit_counts')->default('{}');
            $table->jsonb('state_visit_counts')->default('{}');

            // Behavioral confidence
            $table->integer('trust_score')->default(50);

            // Common patterns
            $table->string('typical_timezone', 50)->nullable();
            $table->string('typical_language', 10)->nullable();

            // Foreign key
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->cascadeOnDelete();

            // Indexes
            $table->unique(['client_id', 'fingerprint_id'], 'idx_client_fingerprint_unique');
            $table->index('fingerprint_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_fingerprints');
    }
};
