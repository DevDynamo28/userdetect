<?php

namespace App\Jobs;

use App\Models\UserDetection;
use App\Services\FingerprintLearningService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LearnFromDetection implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        private UserDetection $detection
    ) {}

    public function handle(FingerprintLearningService $learningService): void
    {
        $learningService->learnIPRange($this->detection);
    }
}
