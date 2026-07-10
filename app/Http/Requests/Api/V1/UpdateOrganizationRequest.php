<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'slug' => [
                'sometimes',
                'string',
                'min:2',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique((new Organization)->getTable(), 'slug')
                    ->ignore($this->route('organization')),
            ],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.call_recording_announcement' => ['sometimes', 'array'],
            'settings.call_recording_announcement.enabled' => ['sometimes', 'boolean'],
            'settings.call_recording_announcement.target' => ['sometimes', Rule::enum(CallRecordingAnnouncementTarget::class)],
            'settings.call_recording_announcement.audio_path' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }
}
