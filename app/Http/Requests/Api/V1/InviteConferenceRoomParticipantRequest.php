<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InviteConferenceRoomParticipantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'user_public_id' => ['nullable', 'string', 'exists:users,public_id', 'required_without:email'],
            'email' => ['nullable', 'email:rfc,dns', 'max:255', 'required_without:user_public_id'],
            'display_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
