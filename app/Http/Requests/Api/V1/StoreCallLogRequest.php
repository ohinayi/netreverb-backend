<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CallStatus;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCallLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'caller_extension_public_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists((new Extension)->getTable(), 'public_id'),
            ],
            'callee_extension_public_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists((new Extension)->getTable(), 'public_id'),
            ],
            'caller_number' => ['required', 'string', 'max:50'],
            'callee_number' => ['required', 'string', 'max:50'],
            'freeswitch_uuid' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', Rule::enum(CallStatus::class)],
            'duration' => ['sometimes', 'integer', 'min:0'],
            'recording_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'recording_duration' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'recording_size' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'started_at' => ['sometimes', 'nullable', 'date'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $organization = $this->route('organization');
            if (! $organization instanceof Organization) {
                return;
            }

            if ($this->filled('caller_extension_public_id')) {
                $callerExists = Extension::query()
                    ->where('public_id', $this->string('caller_extension_public_id'))
                    ->where('organization_id', $organization->id)
                    ->exists();

                if (! $callerExists) {
                    $validator->errors()->add(
                        'caller_extension_public_id',
                        'The selected caller extension must belong to this organization.',
                    );
                }
            }

            if ($this->filled('callee_extension_public_id')) {
                $calleeExists = Extension::query()
                    ->where('public_id', $this->string('callee_extension_public_id'))
                    ->where('organization_id', $organization->id)
                    ->exists();

                if (! $calleeExists) {
                    $validator->errors()->add(
                        'callee_extension_public_id',
                        'The selected callee extension must belong to this organization.',
                    );
                }
            }
        }];
    }
}
