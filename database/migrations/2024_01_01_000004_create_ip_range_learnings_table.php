<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_range_learnings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Learned location
            $table->string('learned_city', 100);
            $table->string('learned_state', 100);

            // Confidence metrics
            $table->integer('sample_count')->default(1);
            $table->decimal('success_rate', 5, 2)->default(100.00);
            $table->decimal('average_confidence', 5, 2)->nullable();

            // Source info
            $table->string('primary_isp', 200)->nullable();
            $table->string('primary_asn', 20)->nullable();
            $table->string('reverse_dns_pattern', 500)->nullable();

            // Quality tracking
            $table->timestamp('last_seen')->useCurrent();
            $table->timestamp('first_seen')->useCurrent();

            // Status
            $table->boolean('is_active')->default(true);

            // Indexes
            $table->index('learned_city');
            $table->index(['sample_count', 'success_rate'], 'idx_confidence');
        });

        // Add CIDR column and GiST index via raw SQL (PostgreSQL-specific)
        DB::statement('ALTER TABLE ip_range_learnings ADD COLUMN ip_range CIDR NOT NULL DEFAULT \'0.0.0.0/0\'');
        DB::statement('CREATE INDEX idx_ip_range_learnings_ip_range ON ip_range_learnings USING gist (ip_range inet_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_range_learnings');
    }
};
