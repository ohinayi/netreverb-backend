<?php

namespace App\Actions\Auth;

use App\Actions\Organizations\CreateOrganization;
use App\Enums\AccountType;
use App\Enums\ExtensionProvisioningMode;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterUser
{
    public function __construct(private CreateOrganization $createOrganization) {}

    public function execute(array $attributes): User
    {
        $user = DB::transaction(function () use ($attributes): User {
            $accountType = AccountType::from($attributes['account_type'] ?? AccountType::Individual->value);

            $user = User::query()->create([
                ...Arr::only($attributes, [
                    'name',
                    'email',
                    'password',
                    'account_type',
                    'country_code',
                    'timezone',
                    'locale',
                ]),
                'terms_accepted_at' => now(),
            ]);

            $this->createOrganization->execute($user, [
                'name' => $accountType === AccountType::Community
                    ? ($attributes['workspace_name'] ?? $user->name.' Community')
                    : $user->name.' Workspace',
                'slug' => Str::slug($accountType === AccountType::Community
                    ? ($attributes['workspace_name'] ?? $user->name.' Community')
                    : $user->name.' Workspace').'-'.Str::lower(Str::random(8)),
                'extension_provisioning_mode' => ExtensionProvisioningMode::Automatic,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'settings' => ['kind' => $accountType->value],
                'assign_owner_extension' => $accountType === AccountType::Individual
                    || (bool) ($attributes['assign_extension'] ?? false),
            ]);

            return $user;
        }, attempts: 3);

        $user->sendEmailVerificationNotification();

        return $user;
    }
}
