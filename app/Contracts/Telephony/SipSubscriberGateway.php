<?php

namespace App\Contracts\Telephony;

use App\Data\SipSubscriber;

interface SipSubscriberGateway
{
    public function upsert(SipSubscriber $subscriber): void;

    public function delete(string $username, string $realm): void;

    public function matches(SipSubscriber $subscriber): bool;
}
