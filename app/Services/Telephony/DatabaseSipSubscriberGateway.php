<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Data\SipSubscriber;
use App\Exceptions\SipProvisioningException;
use App\Models\KamailioSubscriber;
use Throwable;

class DatabaseSipSubscriberGateway implements SipSubscriberGateway
{
    public function upsert(SipSubscriber $subscriber): void
    {
        try {
            KamailioSubscriber::query()->updateOrCreate(
                ['username' => $subscriber->username, 'domain' => $subscriber->realm],
                ['password' => $subscriber->password, 'ha1' => '', 'ha1b' => ''],
            );
        } catch (Throwable) {
            throw SipProvisioningException::databaseOperationFailed();
        }
    }

    public function delete(string $username, string $realm): void
    {
        try {
            KamailioSubscriber::query()
                ->where('username', $username)
                ->where('domain', $realm)
                ->delete();
        } catch (Throwable) {
            throw SipProvisioningException::databaseOperationFailed();
        }
    }

    public function matches(SipSubscriber $subscriber): bool
    {
        try {
            return KamailioSubscriber::query()
                ->where('username', $subscriber->username)
                ->where('domain', $subscriber->realm)
                ->where('password', $subscriber->password)
                ->exists();
        } catch (Throwable) {
            throw SipProvisioningException::databaseOperationFailed();
        }
    }
}
