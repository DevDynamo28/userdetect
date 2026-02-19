<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DetectionResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $data = $this->resource;

        $response = [
            'success' => $data['success'],
            'request_id' => $data['request_id'],
            'user_id' => $data['user_id'],
            'is_new_user' => $data['is_new_user'],
            'location' => [
                'city' => $data['location']['city'],
                'state' => $data['location']['state'],
                'country' => $data['location']['country'],
                'confidence' => $data['location']['confidence'],
                'method' => $data['location']['method'],
            ],
            'vpn_detection' => $data['vpn_detection'],
            'timestamp' => $data['timestamp'],
        ];

        // Add note for low confidence
        if (!empty($data['location']['note'])) {
            $response['location']['note'] = $data['location']['note'];
        }

        // Add alternatives if present
        if (!empty($data['alternatives'])) {
            $response['alternatives'] = $data['alternatives'];
        }

        // Add recommendation if present
        if (!empty($data['recommendation'])) {
            $response['recommendation'] = $data['recommendation'];
        }

        // Add user history if available
        if (!empty($data['user_history'])) {
            $response['user_history'] = $data['user_history'];
        }

        if (!empty($data['diagnostics'])) {
            $response['diagnostics'] = $data['diagnostics'];
        }

        return $response;
    }
}
