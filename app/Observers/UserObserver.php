<?php

namespace App\Observers;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class UserObserver implements ShouldHandleEventsAfterCommit
{
    /**
     * @var array<int, list<array{number: string, realm: string}>>
     */
    private static array $subscriberSnapshots = [];

    public function __construct(private SipSubscriberGateway $sipSubscriberGateway) {}

    public function deleting(User $user): void
    {
        $user->loadMissing(['extensions.dialableNumber']);

        self::$subscriberSnapshots[$user->getKey()] = $user->extensions
            ->map(
                fn (Extension $extension): ?array => $extension->dialableNumber === null
                    ? null
                    : [
                        'number' => $extension->dialableNumber->number,
                        'realm' => $extension->dialableNumber->realm,
                    ],
            )
            ->filter()
            ->values()
            ->all();
    }

    public function deleted(User $user): void
    {
        foreach (self::$subscriberSnapshots[$user->getKey()] ?? [] as $subscriber) {
            $this->sipSubscriberGateway->delete($subscriber['number'], $subscriber['realm']);
        }

        unset(self::$subscriberSnapshots[$user->getKey()]);
    }
}
