<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ServiceNumberType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'type' => ['sometimes', Rule::enum(ServiceNumberType::class)],
            'target' => ['sometimes', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:@-]+$/'],
            'enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
