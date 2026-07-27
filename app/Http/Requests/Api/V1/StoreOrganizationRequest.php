<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'slug' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique((new Organization)->getTable(), 'slug'),
            ],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'locale' => ['sometimes', 'string', 'regex:/^[a-z]{2}(?:-[A-Z]{2})?$/'],
            'assign_owner_extension' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.call_recording_announcement' => ['sometimes', 'array'],
            'settings.call_recording_announcement.enabled' => ['sometimes', 'boolean'],
            'settings.call_recording_announcement.target' => ['sometimes', Rule::enum(CallRecordingAnnouncementTarget::class)],
            'settings.call_recording_announcement.audio_path' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(config('telephony.call_recordings.announcement.allowed_audio_paths', [])),
            ],
        ];
    }
}
