<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_detections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('client_id');
            $table->string('fingerprint_id', 64);
            $table->string('session_id', 64)->nullable();

            // Detection results
            $table->string('detected_city', 100)->nullable();
            $table->string('detected_state', 100)->nullable();
            $table->string('detected_country', 100)->default('India');
            $table->integer('confidence');
            $table->string('detection_method', 50);

            // VPN detection
            $table->boolean('is_vpn')->default(false);
            $table->integer('vpn_confidence')->default(0);
            $table->jsonb('vpn_indicators')->nullable();

            // Network details
            $table->ipAddress('ip_address');
            $table->text('reverse_dns')->nullable();
            $table->string('isp', 200)->nullable();
            $table->string('asn', 20)->nullable();
            $table->string('connection_type', 20)->nullable();

            // Browser/Device
            $table->text('user_agent')->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('os', 100)->nullable();
            $table->string('device_type', 50)->nullable();

            // Signals
            $table->string('timezone', 50)->nullable();
            $table->string('language', 10)->nullable();

            // Ensemble results (debugging)
            $table->jsonb('ip_sources_data')->nullable();

            // Performance
            $table->integer('processing_time_ms')->nullable();

            // Timestamp
            $table->timestamp('detected_at')->useCurrent();

            // Foreign key
            $table->foreign('client_id')
                ->references('id')
                ->on('clients')
                ->cascadeOnDelete();

            // Indexes
            $table->index('fingerprint_id');
            $table->index(['client_id', 'detected_at'], 'idx_client_date');
            $table->index('detection_method');
            $table->index('confidence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_detections');
    }
};
