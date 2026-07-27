<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SecurityBaselineTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_api_responses_include_the_security_baseline_headers(): void
    {
        $this->getJson('/api/v1/email/verify-required')
            ->assertForbidden()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('x-frame-options', 'DENY')
            ->assertHeader('referrer-policy', 'no-referrer')
            ->assertHeader('permissions-policy', 'camera=(), geolocation=(), microphone=()');
    }

    public function test_freeswitch_xml_configuration_requires_a_valid_token_and_loopback_source(): void
    {
        config()->set('telephony.freeswitch.xml_curl_token', 'test-token');
        config()->set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);

        $this->get('/api/freeswitch/callcenter.xml?token=wrong&key_value=callcenter.conf')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.25'])
            ->get('/api/freeswitch/callcenter.xml?token=test-token&key_value=callcenter.conf')
            ->assertForbidden();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/api/freeswitch/callcenter.xml?token=test-token&key_value=callcenter.conf')
            ->assertOk()
            ->assertHeader('content-type', 'text/xml; charset=UTF-8');
    }
}
