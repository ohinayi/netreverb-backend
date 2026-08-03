<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLeadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['new', 'contacted', 'qualified', 'won', 'lost'])],
            'search' => ['sometimes', 'string', 'max:160'],
            'follow_up' => ['sometimes', Rule::in(['overdue', 'upcoming'])],
        ];
    }
}
