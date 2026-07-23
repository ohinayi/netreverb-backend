<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreConferenceRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:2', 'max:120'],
            'passcode' => ['sometimes', 'nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'expires_in_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:10080'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:5', 'max:10080'],
            'configuration' => ['sometimes', 'nullable', 'array'],
            'configuration.is_open' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $startsAt = $this->date('starts_at');
                $expiresAt = $this->date('expires_at');

                if ($startsAt !== null && $expiresAt !== null && $expiresAt->lessThanOrEqualTo($startsAt)) {
                    $validator->errors()->add('expires_at', 'The meeting end time must be after the start time.');
                }
            },
        ];
    }
}
