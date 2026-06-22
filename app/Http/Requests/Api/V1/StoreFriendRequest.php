<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFriendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'addressee_public_id' => ['required', 'string', 'exists:users,public_id'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
