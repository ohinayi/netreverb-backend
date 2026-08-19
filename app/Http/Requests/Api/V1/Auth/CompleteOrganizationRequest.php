<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AccountType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'account_type' => $this->string('account_type')->trim()->lower()->toString(),
            'workspace_name' => $this->filled('workspace_name')
                ? $this->string('workspace_name')->trim()->toString()
                : null,
        ]);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'workspace_name' => [
                Rule::requiredIf(fn (): bool => $this->string('account_type')->toString() === AccountType::Community->value),
                'nullable',
                'string',
                'min:2',
                'max:120',
            ],
            'assign_extension' => ['sometimes', 'boolean'],
            'terms_accepted' => ['required', 'accepted'],
        ];
    }
}
