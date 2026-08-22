<?php

namespace Tests\Feature;

use App\Enums\ServiceNumberType;
use App\Models\Organization;
use App\Models\RingbackAd;
use App\Models\ServiceNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FreeSwitchServiceNumberFallbackTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function allowXmlCurl(): void
    {
        config()->set('telephony.freeswitch.xml_curl_token', 'test-token');
        config()->set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);
    }

    public function test_a_service_number_pointing_at_a_missing_assistant_speaks_unavailable_instead_of_dead_air(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $service = ServiceNumber::factory()->for($organization)->create([
            'type' => ServiceNumberType::Assistant,
            'configuration' => ['ai_assistant_id' => 'does-not-exist'],
        ])->load('dialableNumber');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('temporarily unavailable', $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
    }

    public function test_a_service_number_pointing_at_a_missing_ivr_speaks_unavailable_instead_of_dead_air(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $service = ServiceNumber::factory()->for($organization)->create([
            'type' => ServiceNumberType::Custom,
            'configuration' => ['ivr_public_id' => 'does-not-exist'],
        ])->load('dialableNumber');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('temporarily unavailable', $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
    }

    public function test_a_direct_service_number_bridge_sets_ringback_before_the_bridge_when_the_ad_exempt_org_has_custom_audio(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create([
            'ad_exempt' => true,
            'settings' => ['ringback_audio' => ['audio_path' => 'ringback-audio/'.fake()->uuid().'/hold.wav']],
        ]);
        $service = ServiceNumber::factory()->for($organization)->create([
            'type' => ServiceNumberType::Custom,
            'target' => '9001',
        ])->load('dialableNumber');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('ringback=', $xml);
        $this->assertStringContainsString($organization->ringbackAudioPath(), $xml);
        $this->assertTrue(
            strpos($xml, 'ringback=') < strpos($xml, 'application="bridge"'),
            'ringback must be set before the bridge action runs',
        );
    }

    public function test_a_direct_service_number_bridge_has_no_ringback_override_without_custom_audio(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $service = ServiceNumber::factory()->for($organization)->create([
            'type' => ServiceNumberType::Custom,
            'target' => '9001',
        ])->load('dialableNumber');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $this->assertStringNotContainsString('ringback=', $response->getContent());
    }

    public function test_a_direct_service_number_bridge_plays_a_pool_ad_for_a_non_exempt_org_instead_of_its_own_audio(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create([
            'ad_exempt' => false,
            'settings' => ['ringback_audio' => ['audio_path' => 'ringback-audio/own/hold.wav']],
        ]);
        RingbackAd::query()->create([
            'title' => 'Approved ad', 'audio_path' => 'ringback-ads/pool-ad.wav',
            'status' => 'approved', 'enabled' => true,
        ]);
        $service = ServiceNumber::factory()->for($organization)->create([
            'type' => ServiceNumberType::Custom,
            'target' => '9001',
        ])->load('dialableNumber');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('ringback=', $xml);
        $this->assertStringContainsString('pool-ad.wav', $xml);
        $this->assertStringNotContainsString('ringback-audio/own/hold.wav', $xml);
    }
}
