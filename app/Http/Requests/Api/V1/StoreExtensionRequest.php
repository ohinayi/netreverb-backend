<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ExtensionType;
use App\Enums\MembershipStatus;
use App\Models\DialableNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreExtensionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'number' => [
                'required',
                'string',
                'regex:/^[0-9]{2,15}$/',
                Rule::unique((new DialableNumber)->getTable(), 'number')
                    ->where(fn ($query) => $query->where('realm', config('telephony.sip_realm'))),
            ],
            'display_name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::enum(ExtensionType::class)],
            'user_public_id' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists((new User)->getTable(), 'public_id'),
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('user_public_id')) {
                return;
            }

            $organization = $this->route('organization');
            $user = User::query()->where('public_id', $this->string('user_public_id'))->first();

            if (! $organization instanceof Organization || $user === null) {
                return;
            }

            $isMember = OrganizationMembership::query()
                ->whereBelongsTo($organization)
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Active->value)
                ->exists();

            if (! $isMember) {
                $validator->errors()->add(
                    'user_public_id',
                    'The selected user is not an active member of this organization.',
                );

                return;
            }

            // Only one extension registers per softphone session right now
            // (AppLayout.vue picks the first active user/device extension it
            // finds) - a second assignment would just be unreachable, so
            // block it instead of letting an admin create a dead extension.
            if (Extension::query()->where('user_id', $user->id)->exists()) {
                $validator->errors()->add(
                    'user_public_id',
                    'This user is already assigned to another extension. Only one extension per user is supported right now.',
                );
            }
        }];
    }
}
