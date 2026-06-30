<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StartCallRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'recording_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'freeswitch_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
