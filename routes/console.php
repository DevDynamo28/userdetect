<?php

use Illuminate\Support\Facades\Schedule;

// Retention cleanup for detections and fingerprints.
Schedule::command('data:cleanup-old')->dailyAt('03:00');

// Optional pruning support for models that define prunable rules.
Schedule::command('model:prune', ['--model' => 'App\\Models\\UserDetection'])->dailyAt('03:30');

// Refresh local GeoIP database weekly to keep passive IP detection accuracy stable.
Schedule::command('geoip:update')->weeklyOn(1, '02:30')->withoutOverlapping();
