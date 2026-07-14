<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CallMediaType;
use App\Enums\CallSessionType;
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
            'freeswitch_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::enum(CallStatus::class)],
            'media_type' => ['sometimes', Rule::enum(CallMediaType::class)],
            'session_type' => ['sometimes', Rule::enum(CallSessionType::class)],
            'duration' => ['sometimes', 'integer', 'min:0'],
            'recording_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'recording_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'recording_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
