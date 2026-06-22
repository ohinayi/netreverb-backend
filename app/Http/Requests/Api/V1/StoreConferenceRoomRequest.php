<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConferenceRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'passcode' => ['sometimes', 'nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'expires_in_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:10080'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
