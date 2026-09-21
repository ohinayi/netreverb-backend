<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddCallPartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Same restriction as transfer for phase one: an existing
            // extension in the organization, not an outside number.
            'destination' => ['required', 'string', 'regex:/^\\+?[0-9]{2,20}$/'],
        ];
    }
}
