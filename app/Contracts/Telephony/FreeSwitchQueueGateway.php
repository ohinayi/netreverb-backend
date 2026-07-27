<?php

namespace App\Contracts\Telephony;

use App\Models\CallQueue;

interface FreeSwitchQueueGateway
{
    public function synchronize(CallQueue $queue): void;

    public function remove(string $queueName): void;
}
