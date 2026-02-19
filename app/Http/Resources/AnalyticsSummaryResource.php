<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AnalyticsSummaryResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'success' => true,
            'period' => $data['period'],
            'usage' => [
                'total_requests' => $data['total_requests'],
                'unique_users' => $data['unique_users'],
                'average_confidence' => $data['average_confidence'],
            ],
            'top_cities' => $data['top_cities'],
            'detection_methods' => $data['detection_methods'],
            'vpn_stats' => [
                'total_vpn_detected' => $data['vpn_count'],
                'percentage' => $data['total_requests'] > 0
                    ? round(($data['vpn_count'] / $data['total_requests']) * 100, 1)
                    : 0,
            ],
            'confidence_distribution' => $data['confidence_distribution'],
        ];
    }
}
