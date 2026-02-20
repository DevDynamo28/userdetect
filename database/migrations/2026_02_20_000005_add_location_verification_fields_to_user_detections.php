<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_detections', function (Blueprint $table) {
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
            if (Schema::hasColumn('user_detections', 'verification_source')) {
                $table->dropColumn('verification_source');
            }

            if (Schema::hasColumn('user_detections', 'is_location_verified')) {
                $table->dropColumn('is_location_verified');
            }
        });
    }
};
