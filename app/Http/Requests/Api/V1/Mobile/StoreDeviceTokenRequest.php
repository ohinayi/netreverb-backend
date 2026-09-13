<?php

namespace App\Http\Requests\Api\V1\Mobile;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDeviceTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:android,ios'],
            'fcm_token' => ['required', 'string', 'max:512'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }
}
