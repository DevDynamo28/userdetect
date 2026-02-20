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
