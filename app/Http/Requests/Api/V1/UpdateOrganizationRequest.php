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
            'name' => [
                'sometimes',
                'string',
                'min:2',
                'max:120',
                Rule::unique((new Organization)->getTable(), 'name')
                    ->ignore($this->route('organization')),
            ],
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
            'settings.call_recording_announcement.audio_path' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(config('telephony.call_recordings.announcement.allowed_audio_paths', [])),
            ],
            'settings.operational_policy' => ['sometimes', 'array'],
            'settings.operational_policy.transfers_enabled' => ['sometimes', 'boolean'],
            'settings.operational_policy.agent_recording_enabled' => ['sometimes', 'boolean'],
            'settings.operational_policy.supervisor_recording_access' => ['sometimes', 'boolean'],
            'settings.operational_policy.supervisor_queue_management' => ['sometimes', 'boolean'],
            'settings.operational_policy.recording_retention_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'settings.operational_policy.campaigns_enabled' => ['sometimes', 'boolean'],
            'settings.operational_policy.campaign_max_recipients' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'settings.operational_policy.campaign_rate_limit_per_minute' => ['sometimes', 'integer', 'min:1', 'max:300'],
        ];
    }
}
