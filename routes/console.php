<?php

use Illuminate\Support\Facades\Schedule;

// Clean up old detections (archive data older than 90 days)
Schedule::command('model:prune', ['--model' => 'App\\Models\\UserDetection'])->daily();
