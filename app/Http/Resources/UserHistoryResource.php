<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserHistoryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $fingerprint = $this->resource['fingerprint'];
        $locationHistory = $this->resource['location_history'];

        return [
            'success' => true,
            'fingerprint_id' => $fingerprint->fingerprint_id,
            'visit_count' => $fingerprint->visit_count,
            'first_seen' => $fingerprint->first_seen?->toIso8601String(),
            'last_seen' => $fingerprint->last_seen?->toIso8601String(),
            'trust_score' => $fingerprint->trust_score,
            'location_history' => $locationHistory,
            'typical_patterns' => [
                'timezone' => $fingerprint->typical_timezone,
                'language' => $fingerprint->typical_language,
                'typical_city' => $fingerprint->typical_city,
                'typical_state' => $fingerprint->typical_state,
            ],
        ];
    }
}
