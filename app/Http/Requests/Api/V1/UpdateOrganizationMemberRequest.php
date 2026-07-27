<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'department_public_id' => ['sometimes', 'nullable', 'string', 'exists:departments,public_id'],
            'role' => ['sometimes', 'string', 'in:admin,supervisor,agent'],
            'status' => ['sometimes', 'string', 'in:invited,active,suspended'],
        ];
    }
}
