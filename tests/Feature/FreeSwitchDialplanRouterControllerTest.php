<?php

namespace Tests\Feature;

use App\Models\DialableNumber;
use App\Models\ServiceNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreeSwitchDialplanRouterControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function configureRouter(): void
    {
        Config::set('telephony.freeswitch.xml_curl_token', 'test-token');
        Config::set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);
        Config::set('telephony.freeswitch.xml_curl_local_test_extensions', ['100005']);
        Config::set('telephony.freeswitch.xml_curl_local_tunnel_url', 'http://127.0.0.1:8001/api/freeswitch/dialplan.xml');
    }

    public function test_a_local_test_caller_still_resolves_a_production_service_number_locally_registered_or_not(): void
    {
        $this->configureRouter();

        $serviceNumber = ServiceNumber::factory()->create(['enabled' => true]);
        $serviceNumber->dialableNumber()->update(['number' => '23422222']);

        Http::fake([
            '127.0.0.1:8001/*' => Http::response('SHOULD_NOT_BE_CALLED', 200),
        ]);

        $response = $this->postJson('/api/freeswitch/dialplan-router.xml?token=test-token', [
            'destination_number' => '23422222',
            'Caller-Caller-ID-Number' => '100005',
            'context' => 'public',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_a_local_test_caller_falls_back_to_the_local_tunnel_when_production_does_not_know_the_number(): void
    {
        $this->configureRouter();

        Http::fake([
            'http://127.0.0.1:8001/*' => Http::response('<local-only-xml/>', 200, ['Content-Type' => 'text/xml']),
        ]);

        $response = $this->postJson('/api/freeswitch/dialplan-router.xml?token=test-token', [
            'destination_number' => '23444444',
            'Caller-Caller-ID-Number' => '100005',
            'context' => 'public',
        ]);

        $response->assertOk();
        $response->assertSee('<local-only-xml/>', false);
        Http::assertSent(fn ($request) => str_starts_with((string) $request->url(), 'http://127.0.0.1:8001/'));
    }

    public function test_a_non_local_test_caller_always_uses_production_regardless_of_the_number(): void
    {
        $this->configureRouter();

        Http::fake([
            '127.0.0.1:8001/*' => Http::response('SHOULD_NOT_BE_CALLED', 200),
        ]);

        $response = $this->postJson('/api/freeswitch/dialplan-router.xml?token=test-token', [
            'destination_number' => '23444444',
            'Caller-Caller-ID-Number' => '232100',
            'context' => 'public',
        ]);

        $response->assertOk();
        Http::assertNothingSent();
    }

    public function test_falls_through_to_production_when_the_local_tunnel_is_unreachable(): void
    {
        $this->configureRouter();

        Http::fake([
            '127.0.0.1:8001/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'),
        ]);

        $response = $this->postJson('/api/freeswitch/dialplan-router.xml?token=test-token', [
            'destination_number' => '999999999',
            'Caller-Caller-ID-Number' => '100005',
            'context' => 'public',
        ]);

        $response->assertOk();
    }
}
