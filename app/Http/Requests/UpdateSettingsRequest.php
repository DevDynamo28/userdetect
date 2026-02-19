<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:clients,email,' . auth()->id(),
            'allowed_domains' => 'nullable|string|max:2000',
            'webhook_url' => 'nullable|url|max:500',
            'webhook_enabled' => 'nullable|boolean',
        ];
    }
}
