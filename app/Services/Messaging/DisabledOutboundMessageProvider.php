<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\OutboundMessageProvider;
use App\Models\OutboundMessage;
use RuntimeException;

class DisabledOutboundMessageProvider implements OutboundMessageProvider
{
    public function send(OutboundMessage $message): array
    {
        throw new RuntimeException('Live outbound messaging is disabled.');
    }
}
