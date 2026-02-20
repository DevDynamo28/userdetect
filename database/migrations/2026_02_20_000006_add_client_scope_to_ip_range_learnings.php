<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ip_range_learnings', function (Blueprint $table) {
            if (!Schema::hasColumn('ip_range_learnings', 'client_id')) {
                $table->uuid('client_id')->nullable();
                $table->index('client_id', 'idx_ip_range_learnings_client_id');
            }
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('ip_range_learnings', function (Blueprint $table) {
                $table->foreign('client_id')
                    ->references('id')
                    ->on('clients')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('ip_range_learnings', function (Blueprint $table) {
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['client_id']);
            }

            if (Schema::hasColumn('ip_range_learnings', 'client_id')) {
                $table->dropIndex('idx_ip_range_learnings_client_id');
                $table->dropColumn('client_id');
            }
        });
    }
};
