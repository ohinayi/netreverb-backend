<?php

namespace App\Actions\Auth;

use App\Actions\Organizations\CreateOrganization;
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
            $user = User::query()->create([
                ...Arr::only($attributes, [
                    'name',
                    'email',
                    'password',
                    'country_code',
                    'timezone',
                    'locale',
                ]),
                'terms_accepted_at' => now(),
            ]);

            $this->createOrganization->execute($user, [
                'name' => $user->name.' Workspace',
                'slug' => Str::slug($user->name).'-'.Str::lower(Str::random(8)),
                'extension_provisioning_mode' => ExtensionProvisioningMode::Automatic,
                'timezone' => $user->timezone,
                'locale' => $user->locale,
                'settings' => ['kind' => 'personal'],
            ]);

            return $user;
        }, attempts: 3);

        $user->sendEmailVerificationNotification();

        return $user;
    }
}
