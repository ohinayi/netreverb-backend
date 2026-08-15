<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAiAssistantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'extension_public_id' => ['nullable', 'string', 'max:26'],
            'enabled' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'string', 'max:16'],
            'tts_voice' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(config('tts.piper.voices', [])))],
            'welcome_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'closing_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'system_instruction' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'knowledge' => ['sometimes', 'nullable', 'array'],
            'handoff_rules' => ['sometimes', 'nullable', 'array'],
            'fields' => ['sometimes', 'array', 'max:30'],
            'fields.*.key' => ['required_with:fields', 'string', 'max:64', 'alpha_dash', 'distinct'],
            'fields.*.label' => ['required_with:fields', 'string', 'max:120'],
            'fields.*.field_type' => [
                'required_with:fields',
                Rule::in(['text', 'long_text', 'number', 'phone', 'email', 'date', 'select', 'boolean']),
            ],
            'fields.*.question' => ['nullable', 'string', 'max:1000'],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:50'],
            'fields.*.options.*' => ['string', 'max:120'],
            'fields.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
        ];
    }
}
