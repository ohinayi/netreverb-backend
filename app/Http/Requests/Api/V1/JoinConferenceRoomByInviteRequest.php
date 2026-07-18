<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class JoinConferenceRoomByInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'invite_code' => ['required', 'string', 'size:22'],
            'display_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'passcode' => ['sometimes', 'nullable', 'string', 'max:20'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
