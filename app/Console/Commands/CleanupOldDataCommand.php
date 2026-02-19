<?php

namespace App\Console\Commands;

use App\Models\UserDetection;
use App\Models\UserFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupOldDataCommand extends Command
{
    protected $signature = 'data:cleanup-old';

    protected $description = 'Delete old detections and fingerprint records based on retention policy.';

    public function handle(): int
    {
        $detectionRetentionDays = (int) config('detection.learning.retention_days', 90);
        $fingerprintRetentionDays = (int) config('detection.learning.fingerprint_retention_days', 90);

        $detectionsDeleted = UserDetection::query()
            ->where('detected_at', '<', now()->subDays($detectionRetentionDays))
            ->delete();

        $fingerprintsDeleted = UserFingerprint::query()
            ->where('last_seen', '<', now()->subDays($fingerprintRetentionDays))
            ->delete();

        $message = sprintf(
            'Retention cleanup complete. detections_deleted=%d fingerprints_deleted=%d',
            $detectionsDeleted,
            $fingerprintsDeleted
        );

        $this->info($message);
        Log::info($message, [
            'detection_retention_days' => $detectionRetentionDays,
            'fingerprint_retention_days' => $fingerprintRetentionDays,
        ]);

        return self::SUCCESS;
    }
}

