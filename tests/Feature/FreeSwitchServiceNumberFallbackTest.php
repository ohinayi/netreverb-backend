<?php

namespace Tests\Feature;

use App\Enums\ServiceNumberType;
use App\Models\Organization;
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
}
