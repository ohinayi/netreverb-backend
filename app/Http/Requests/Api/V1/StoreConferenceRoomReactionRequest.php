<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConferenceRoomReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'reaction_type' => ['required', 'string', 'min:1', 'max:64'],
            'expires_in_seconds' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:300'],
        ];
    }
}
