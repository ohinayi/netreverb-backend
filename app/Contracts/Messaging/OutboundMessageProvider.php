<?php

namespace App\Contracts\Messaging;

use App\Models\OutboundMessage;

interface OutboundMessageProvider
{
    /**
     * @return array{provider: string, message_id: string}
     */
    public function send(OutboundMessage $message): array;
}
