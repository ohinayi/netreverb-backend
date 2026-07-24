<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InviteOrganizationMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id', 'required_without:email'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255', 'required_without:user_public_id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'department_public_id' => ['sometimes', 'nullable', 'string', 'exists:departments,public_id'],
            'role' => ['sometimes', 'nullable', 'in:owner,admin,telephony_admin,department_manager,supervisor,auditor,member'],
        ];
    }
}
