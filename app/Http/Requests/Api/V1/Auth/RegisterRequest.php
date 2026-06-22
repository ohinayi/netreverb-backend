<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\AccountType;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->string('email')->trim()->lower()->toString(),
            'country_code' => $this->string('country_code')->trim()->upper()->toString(),
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
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:254',
                Rule::unique((new User)->getTable(), 'email'),
            ],
            'password' => [
                'required',
                'confirmed',
                'max:72',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'country_code' => ['required', 'string', 'size:2', 'alpha:ascii'],
            'timezone' => ['required', 'string', 'timezone:all'],
            'locale' => ['required', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'account_type' => ['required', Rule::enum(AccountType::class)],
            'workspace_name' => [
                Rule::requiredIf(fn (): bool => $this->string('account_type')->toString() === AccountType::Community->value),
                'nullable',
                'string',
                'min:2',
                'max:120',
            ],
            'terms_accepted' => ['required', 'accepted'],
            'device_name' => ['sometimes', 'string', 'min:2', 'max:100'],
        ];
    }
}
