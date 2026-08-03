<?php

namespace Tests\Unit;

use App\Exceptions\Messaging\IndeterminateOutboundMessageException;
use App\Exceptions\Messaging\PermanentOutboundMessageException;
use App\Models\OutboundMessage;
use App\Services\Messaging\EBulkSmsOutboundMessageProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EBulkSmsOutboundMessageProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('outbound.providers.ebulksms', [
            'endpoint' => 'https://api.ebulksms.test/sendsms.json',
            'username' => 'owner@example.com',
            'api_key' => 'test-key',
            'sender' => 'NetReverb',
            'dnd_sender' => false,
            'timeout' => 20,
        ]);
    }

    public function test_it_maps_an_approved_sms_to_the_official_json_contract(): void
    {
        Http::fake([
            'api.ebulksms.test/*' => Http::response([
                'response' => ['status' => 'SUCCESS', 'totalsent' => 1, 'cost' => 1],
            ]),
        ]);
        $message = $this->message();

        $result = app(EBulkSmsOutboundMessageProvider::class)->send($message);

        $this->assertSame([
            'provider' => 'ebulksms',
            'message_id' => 'campaign:01TEST:lead:01LEAD',
        ], $result);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.ebulksms.test/sendsms.json'
            && $request['SMS']['auth']['username'] === 'owner@example.com'
            && $request['SMS']['message']['sender'] === 'NetReverb'
            && $request['SMS']['recipients']['gsm'][0] === [
                'msidn' => '2348012345678',
                'msgid' => 'campaign:01TEST:lead:01LEAD',
            ]);
    }

    public function test_it_rejects_a_local_number_before_making_a_provider_request(): void
    {
        Http::fake();
        $message = $this->message()->forceFill(['destination' => '08012345678']);

        try {
            app(EBulkSmsOutboundMessageProvider::class)->send($message);
            $this->fail('A local-format recipient should not reach eBulkSMS.');
        } catch (PermanentOutboundMessageException) {
            // Expected safety rejection.
        }
        Http::assertNothingSent();
    }

    public function test_it_marks_server_failures_as_indeterminate_instead_of_blindly_retrying(): void
    {
        Http::fake(['api.ebulksms.test/*' => Http::response('Unavailable', 503)]);

        $this->expectException(IndeterminateOutboundMessageException::class);

        app(EBulkSmsOutboundMessageProvider::class)->send($this->message());
    }

    private function message(): OutboundMessage
    {
        return (new OutboundMessage)->forceFill([
            'public_id' => '01MESSAGE',
            'idempotency_key' => 'campaign:01TEST:lead:01LEAD',
            'channel' => 'sms',
            'destination' => '+234 801 234 5678',
            'body' => 'Hello Ada, your appointment is tomorrow.',
        ]);
    }
}
