<?php

namespace Tests\Unit;

use App\Services\Messaging\OutboundMessagingReadiness;
use Tests\TestCase;

class OutboundMessagingReadinessTest extends TestCase
{
    public function test_a_configured_provider_remains_blocked_until_live_sending_is_enabled(): void
    {
        $this->configureEBulkSms();
        config()->set('outbound.sending_enabled', false);

        $readiness = app(OutboundMessagingReadiness::class);

        $this->assertSame([
            'enabled' => false,
            'provider' => 'ebulksms',
            'configured' => true,
        ], $readiness->status());
        $this->assertFalse($readiness->canSend());
    }

    public function test_live_sending_requires_every_required_provider_setting(): void
    {
        $this->configureEBulkSms();
        config()->set('outbound.sending_enabled', true);
        $readiness = app(OutboundMessagingReadiness::class);

        $this->assertTrue($readiness->canSend());

        config()->set('outbound.providers.ebulksms.sender', '');

        $this->assertFalse($readiness->canSend());
        $this->assertFalse($readiness->status()['configured']);
    }

    private function configureEBulkSms(): void
    {
        config()->set('outbound.provider', 'ebulksms');
        config()->set('outbound.providers.ebulksms', [
            'username' => 'owner@example.com',
            'api_key' => 'test-key',
            'sender' => 'NetReverb',
        ]);
    }
}
