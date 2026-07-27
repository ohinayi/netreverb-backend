<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCallQueueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'extension_public_id' => ['required', 'string'],
            'department_public_id' => ['sometimes', 'nullable', 'string', 'exists:departments,public_id'],
            'strategy' => ['required', Rule::in(['top-down', 'longest-idle-agent', 'round-robin', 'ring-all'])],
            'agent_ring_timeout_seconds' => ['required', 'integer', 'min:10', 'max:60'],
            'max_wait_seconds' => ['required', 'integer', 'min:30', 'max:3600'],
            'empty_queue_action' => ['required', Rule::in(['end_call', 'forward_to_extension'])],
            'fallback_extension_id' => ['nullable', 'string'],
            'enabled' => ['sometimes', 'boolean'],
            'members' => ['present', 'array', 'max:100'],
            'members.*.extension_public_id' => ['required', 'string', 'distinct'],
            'members.*.priority' => ['required', 'integer', 'min:1', 'max:100'],
            'members.*.enabled' => ['sometimes', 'boolean'],
        ];
    }
}
