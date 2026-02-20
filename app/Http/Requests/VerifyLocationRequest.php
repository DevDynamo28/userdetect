<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled by middleware.
    }

    public function rules(): array
    {
        return [
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'source' => 'required|string|in:checkout,profile,crm,support,manual',
            'backfill_hours' => 'nullable|integer|min:1|max:720',
            'max_records' => 'nullable|integer|min:1|max:500',
            'event_timestamp' => 'nullable|date',
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
