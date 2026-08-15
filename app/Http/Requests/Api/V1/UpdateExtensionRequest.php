<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use App\Enums\MembershipStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'type' => ['sometimes', Rule::enum(ExtensionType::class)],
            'status' => [
                'sometimes',
                Rule::in([
                    ExtensionStatus::Active->value,
                    ExtensionStatus::Suspended->value,
                    ExtensionStatus::Disabled->value,
                ]),
            ],
            'user_public_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists((new User)->getTable(), 'public_id'),
            ],
            'unavailable_action' => ['sometimes', Rule::in(['return_to_sender', 'forward_to_extension', 'end_call'])],
            'fallback_extension_id' => ['sometimes', 'nullable', 'string', Rule::exists((new Extension)->getTable(), 'public_id')],
            'ring_timeout_seconds' => ['sometimes', 'integer', 'min:10', 'max:60'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $organization = $this->route('organization');
            if ($this->filled('user_public_id')) {
                $user = User::query()->where('public_id', $this->string('user_public_id'))->first();
                $current = $this->route('extension');

                if ($organization instanceof Organization && $user !== null && ! OrganizationMembership::query()
                    ->whereBelongsTo($organization)
                    ->whereBelongsTo($user)
                    ->where('status', MembershipStatus::Active->value)
                    ->exists()) {
                    $validator->errors()->add(
                        'user_public_id',
                        'The selected user is not an active member of this organization.',
                    );
                } elseif ($organization instanceof Organization && $user !== null && Extension::query()
                    ->where('user_id', $user->id)
                    ->when($current instanceof Extension, fn ($query) => $query->whereKeyNot($current->id))
                    ->exists()) {
                    // Only one extension registers per softphone session right
                    // now (AppLayout.vue picks the first active user/device
                    // extension it finds) - a second assignment would just be
                    // unreachable, so block it instead of allowing a dead one.
                    $validator->errors()->add(
                        'user_public_id',
                        'This user is already assigned to another extension. Only one extension per user is supported right now.',
                    );
                }
            }

            if (! $this->filled('fallback_extension_id') || ! $organization instanceof Organization) {
                return;
            }

            $fallback = Extension::query()->where('public_id', $this->string('fallback_extension_id'))->first();
            $current = $this->route('extension');
            if ($fallback === null || $fallback->organization_id !== $organization->id) {
                $validator->errors()->add(
                    'fallback_extension_id',
                    'The fallback extension must belong to this organization.',
                );
            } elseif ($current instanceof Extension && $fallback->id === $current->id) {
                $validator->errors()->add('fallback_extension_id', 'An extension cannot be its own fallback.');
            }
        }];
    }
}
