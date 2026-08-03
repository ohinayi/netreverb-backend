<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'min:2', 'max:160'],
            'company' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email:rfc,dns', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'won', 'lost'])],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'assigned_user_public_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'last_contacted_at' => ['sometimes', 'nullable', 'date'],
            'follow_up_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
