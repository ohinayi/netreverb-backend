<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:direct,community,group'],
            'community_public_id' => ['sometimes', 'nullable', 'string', 'exists:communities,public_id'],
            'title' => ['sometimes', 'nullable', 'string', 'min:2', 'max:120'],
            'participant_public_ids' => ['sometimes', 'array'],
            'participant_public_ids.*' => ['string', 'exists:users,public_id'],
        ];
    }
}
