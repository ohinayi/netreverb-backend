<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TransferCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            // Deliberately strict for phase one: extensions and E.164 numbers.
            'destination' => ['required', 'string', 'regex:/^\\+?[0-9]{2,20}$/'],
        ];
    }
}
