<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TranslateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'target_locale' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/i'],
        ];
    }
}
