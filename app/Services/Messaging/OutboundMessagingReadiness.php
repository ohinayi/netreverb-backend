<?php

namespace App\Services\Messaging;

class OutboundMessagingReadiness
{
    /**
     * @return array{enabled: bool, provider: string, configured: bool}
     */
    public function status(): array
    {
        $provider = (string) config('outbound.provider', 'disabled');
        $configured = match ($provider) {
            'ebulksms' => collect([
                config('outbound.providers.ebulksms.username'),
                config('outbound.providers.ebulksms.api_key'),
                config('outbound.providers.ebulksms.sender'),
            ])->every(fn ($value) => is_string($value) && trim($value) !== ''),
            default => false,
        };

        return [
            'enabled' => (bool) config('outbound.sending_enabled'),
            'provider' => $provider,
            'configured' => $configured,
        ];
    }

    public function canSend(): bool
    {
        $status = $this->status();

        return $status['enabled'] && $status['configured'];
    }
}
