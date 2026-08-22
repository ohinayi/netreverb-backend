<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationIvr;
use App\Models\OrganizationIvrOption;
use App\Models\RingbackAd;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class FreeSwitchIvrDialplanTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function allowXmlCurl(): void
    {
        config()->set('telephony.freeswitch.xml_curl_token', 'test-token');
        config()->set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);
    }

    public function test_a_submenu_option_transfers_into_the_nested_ivrs_own_options_context(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $child = OrganizationIvr::create([
            'organization_id' => $organization->id,
            'name' => 'Dressing help',
            'welcome_text' => 'Press 1 for shoes.',
            'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $child->id, 'digit' => '1', 'label' => 'Shoes',
            'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe and tie the laces.',
            'enabled' => true,
        ]);
        $parent = OrganizationIvr::create([
            'organization_id' => $organization->id,
            'name' => 'Main menu',
            'welcome_text' => 'Welcome. Press 1 for dressing issues.',
            'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $parent->id, 'digit' => '1', 'label' => 'Dressing issues',
            'destination_type' => 'submenu', 'destination' => $child->public_id, 'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$parent->public_id.'&destination_number=1'
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('Press 1 for shoes.', $xml);
        $this->assertStringContainsString('ivr-options-'.$child->public_id, $xml);
        $this->assertStringContainsString('application="read"', $xml);
        $this->assertStringContainsString('application="transfer"', $xml);
    }

    public function test_a_directive_option_plays_its_message_then_hangs_up(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $ivr = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Shoes', 'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $ivr->id, 'digit' => '1', 'label' => 'Shoes',
            'destination_type' => 'directive', 'directive_text' => 'Put your foot in the shoe and tie the laces.',
            'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$ivr->public_id.'&destination_number=1'
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('Put your foot in the shoe and tie the laces.', $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
        $this->assertStringContainsString('NORMAL_CLEARING', $xml);
    }

    public function test_a_submenu_pointing_at_a_missing_ivr_hangs_up_instead_of_erroring(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $ivr = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Broken menu', 'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $ivr->id, 'digit' => '1', 'label' => 'Nowhere',
            'destination_type' => 'submenu', 'destination' => 'does-not-exist', 'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$ivr->public_id.'&destination_number=1'
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('application="hangup"', $xml);
        $this->assertStringContainsString('NORMAL_CLEARING', $xml);
    }

    public function test_an_ivr_extension_transfer_sets_ringback_before_the_bridge_when_the_ad_exempt_org_has_custom_audio(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create([
            'ad_exempt' => true,
            'settings' => ['ringback_audio' => ['audio_path' => 'ringback-audio/'.fake()->uuid().'/hold.wav']],
        ]);
        $ivr = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Sales', 'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $ivr->id, 'digit' => '1', 'label' => 'Agent',
            'destination_type' => 'extension', 'destination' => '9001', 'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$ivr->public_id.'&destination_number=1'
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

    public function test_an_ivr_extension_transfer_has_no_ringback_override_without_custom_audio(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $ivr = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Sales', 'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $ivr->id, 'digit' => '1', 'label' => 'Agent',
            'destination_type' => 'extension', 'destination' => '9001', 'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$ivr->public_id.'&destination_number=1'
        );

        $response->assertOk();
        $this->assertStringNotContainsString('ringback=', $response->getContent());
    }

    public function test_an_ivr_extension_transfer_plays_a_pool_ad_for_a_non_exempt_org_instead_of_its_own_audio(): void
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
        $ivr = OrganizationIvr::create([
            'organization_id' => $organization->id, 'name' => 'Sales', 'enabled' => true,
        ]);
        OrganizationIvrOption::create([
            'organization_ivr_id' => $ivr->id, 'digit' => '1', 'label' => 'Agent',
            'destination_type' => 'extension', 'destination' => '9001', 'enabled' => true,
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='
                .'ivr-options-'.$ivr->public_id.'&destination_number=1'
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('ringback=', $xml);
        $this->assertStringContainsString('pool-ad.wav', $xml);
        $this->assertStringNotContainsString('ringback-audio/own/hold.wav', $xml);
    }
}
