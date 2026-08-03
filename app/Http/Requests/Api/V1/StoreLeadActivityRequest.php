<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['note', 'call_outcome', 'sms_outcome', 'email_outcome'])],
            'summary' => ['required', 'string', 'min:2', 'max:4000'],
            'call_log_public_id' => ['nullable', 'required_if:type,call_outcome', 'string', 'ulid'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
