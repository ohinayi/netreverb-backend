<?php

namespace App\Http\Requests\Api\V1\Auth;

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
            'terms_accepted' => ['required', 'accepted'],
            'device_name' => ['sometimes', 'string', 'min:2', 'max:100'],
        ];
    }
}
