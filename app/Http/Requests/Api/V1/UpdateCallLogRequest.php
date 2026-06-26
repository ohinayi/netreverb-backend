<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CallStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCallLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(CallStatus::class)],
            'duration' => ['sometimes', 'integer', 'min:0'],
            'recording_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'recording_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'recording_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
