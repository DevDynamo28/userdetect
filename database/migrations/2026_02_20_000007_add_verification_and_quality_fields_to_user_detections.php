<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_detections', function (Blueprint $table) {
            if (!Schema::hasColumn('user_detections', 'verified_city')) {
                $table->string('verified_city', 100)->nullable()->index();
            }

            if (!Schema::hasColumn('user_detections', 'verified_state')) {
                $table->string('verified_state', 100)->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'verified_country')) {
                $table->string('verified_country', 100)->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'verification_received_at')) {
                $table->timestamp('verification_received_at')->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'state_disagreement_count')) {
                $table->unsignedInteger('state_disagreement_count')->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'city_disagreement_count')) {
                $table->unsignedInteger('city_disagreement_count')->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'fallback_reason')) {
                $table->string('fallback_reason', 100)->nullable();
            }

            if (!Schema::hasColumn('user_detections', 'is_location_verified')) {
                $table->boolean('is_location_verified')->default(false);
            }

            if (!Schema::hasColumn('user_detections', 'verification_source')) {
                $table->string('verification_source', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_detections', function (Blueprint $table) {
            if (Schema::hasColumn('user_detections', 'fallback_reason')) {
                $table->dropColumn('fallback_reason');
            }

            if (Schema::hasColumn('user_detections', 'city_disagreement_count')) {
                $table->dropColumn('city_disagreement_count');
            }

            if (Schema::hasColumn('user_detections', 'state_disagreement_count')) {
                $table->dropColumn('state_disagreement_count');
            }

            if (Schema::hasColumn('user_detections', 'verification_received_at')) {
                $table->dropColumn('verification_received_at');
            }

            if (Schema::hasColumn('user_detections', 'verified_country')) {
                $table->dropColumn('verified_country');
            }

            if (Schema::hasColumn('user_detections', 'verified_state')) {
                $table->dropColumn('verified_state');
            }

            if (Schema::hasColumn('user_detections', 'verified_city')) {
                $table->dropIndex(['verified_city']);
                $table->dropColumn('verified_city');
            }
        });
    }
};
