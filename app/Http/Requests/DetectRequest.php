<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DetectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    public function rules(): array
    {
        return [
            'signals' => 'required|array',
            'signals.fingerprint' => 'required|string|min:16|max:128',
            'signals.timezone' => 'nullable|string|max:50',
            'signals.timezone_offset' => 'nullable|integer',
            'signals.language' => 'nullable|string|max:10',
            'signals.languages' => 'nullable|array',
            'signals.user_agent' => 'nullable|string|max:500',
            'signals.screen' => 'nullable|array',
            'signals.screen.width' => 'nullable|integer',
            'signals.screen.height' => 'nullable|integer',
            'signals.screen.color_depth' => 'nullable|integer',
            'signals.platform' => 'nullable|string|max:50',
            'signals.location_verification' => 'nullable|array',
            'signals.location_verification.city' => 'nullable|string|max:100',
            'signals.location_verification.state' => 'nullable|string|max:100',
            'signals.location_verification.country' => 'nullable|string|max:100',
            'signals.location_verification.source' => 'nullable|string|in:checkout,profile,crm,support,manual',
            // Browser network probe — CF /cdn-cgi/trace data + navigator.connection
            'signals.network_probes' => 'nullable|array',
            'signals.network_probes.cf_trace' => 'nullable|array',
            'signals.network_probes.cf_trace.colo' => 'nullable|string|max:10',
            'signals.network_probes.cf_trace.ip' => 'nullable|ip',
            'signals.network_probes.cf_trace.rtt_ms' => 'nullable|integer|min:0|max:5000',
            'signals.network_probes.connection' => 'nullable|array',
            'signals.network_probes.connection.type' => 'nullable|string|max:20',
            'signals.network_probes.connection.effective_type' => 'nullable|string|max:10',
            'signals.network_probes.connection.rtt' => 'nullable|integer|min:0|max:30000',
            'signals.network_probes.connection.downlink' => 'nullable|numeric|min:0',
            // WebRTC ICE host candidate local IPs (CGN subnet → ISP gateway city)
            'signals.network_probes.webrtc' => 'nullable|array',
            'signals.network_probes.webrtc.local_ips' => 'nullable|array|max:8',
            'signals.network_probes.webrtc.local_ips.*' => 'nullable|ip',
            'signals.network_probes.webrtc.connection_type' => 'nullable|string|in:cgn_cellular,private_wifi,link_local,unknown',
            'options' => 'nullable|array',
            'options.return_alternatives' => 'nullable|boolean',
            'options.include_debug_info' => 'nullable|boolean',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'Invalid request parameters.',
                'fields' => $validator->errors()->toArray(),
            ],
        ], 422));
    }
}
