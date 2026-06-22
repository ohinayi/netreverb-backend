<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JoinConferenceRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'passcode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
