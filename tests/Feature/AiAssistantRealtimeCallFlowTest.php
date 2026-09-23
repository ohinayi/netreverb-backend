<?php

namespace Tests\Feature;

use App\Enums\AiAssistantResponseMode;
use App\Enums\ServiceNumberType;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Organization;
use App\Models\ServiceNumber;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AiAssistantRealtimeCallFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function allowXmlCurl(): void
    {
        config()->set('telephony.freeswitch.xml_curl_token', 'test-token');
        config()->set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);
        config()->set('telephony.ai_assistant_realtime.bridge_host', '127.0.0.1');
        config()->set('telephony.ai_assistant_realtime.bridge_port', 8022);
        config()->set('telephony.ai_assistant_realtime.bridge_token', 'bridge-test-token');
    }

    private function serviceNumberFor(AiAssistant $assistant): ServiceNumber
    {
        return ServiceNumber::factory()->for($assistant->organization)->create([
            'type' => ServiceNumberType::Assistant,
            'configuration' => ['ai_assistant_id' => $assistant->public_id],
        ])->load('dialableNumber');
    }

    public function test_dialing_a_speech_to_speech_assistant_starts_the_audio_stream_and_parks(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime Intake',
            'enabled' => true,
            'response_mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'welcome_message' => 'Hi, how can I help?',
            'system_instruction' => 'You are a friendly test assistant.',
        ]);
        $service = $this->serviceNumberFor($assistant);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('application="answer"', $xml);
        $this->assertStringContainsString('application="api"', $xml);
        $this->assertStringContainsString('uuid_audio_stream ${uuid} start ws://127.0.0.1:8022/session/', $xml);
        $this->assertStringContainsString('mono 16k', $xml);
        $this->assertStringContainsString('application="park"', $xml);
        // The turn-based flow's record/read/DTMF-question machinery must
        // never appear for a speech-to-speech assistant.
        $this->assertStringNotContainsString('application="record"', $xml);
        $this->assertStringNotContainsString('application="read"', $xml);

        $session = AiAssistantSession::query()->sole();
        $this->assertSame('in_progress', $session->status);
        $this->assertSame(AiAssistantResponseMode::SpeechToSpeech, $session->mode);
        $this->assertNotEmpty($session->bridge_session_token);
        $this->assertStringContainsString($session->bridge_session_token, $xml);
    }

    public function test_dialing_a_turn_based_assistant_still_uses_the_existing_flow(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Turn-Based Intake',
            'enabled' => true,
            'welcome_message' => 'Welcome.',
        ]);
        $service = $this->serviceNumberFor($assistant);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringNotContainsString('uuid_audio_stream', $xml);
        $this->assertStringNotContainsString('application="park"', $xml);

        $session = AiAssistantSession::query()->sole();
        $this->assertSame(AiAssistantResponseMode::TurnBased, $session->mode);
        $this->assertNull($session->bridge_session_token);
    }

    public function test_bridge_can_fetch_session_config_with_a_valid_token(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime Intake',
            'enabled' => true,
            'response_mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'system_instruction' => 'Be brief.',
            'welcome_message' => 'Hello there.',
        ]);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress',
            'mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'bridge_session_token' => 'a-real-token',
            'started_at' => now(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/internal/realtime-bridge/sessions/a-real-token?token=bridge-test-token');

        $response->assertOk()
            ->assertJsonPath('session.id', $session->public_id)
            ->assertJsonPath('assistant.system_instruction', 'Be brief.')
            ->assertJsonPath('assistant.welcome_message', 'Hello there.')
            ->assertJsonPath('assistant.language', 'en');
    }

    public function test_bridge_session_lookup_rejects_a_wrong_token(): void
    {
        $this->allowXmlCurl();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/api/internal/realtime-bridge/sessions/anything?token=wrong')
            ->assertForbidden();
    }

    public function test_bridge_session_lookup_rejects_a_non_loopback_ip(): void
    {
        $this->allowXmlCurl();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->getJson('/api/internal/realtime-bridge/sessions/anything?token=bridge-test-token')
            ->assertForbidden();
    }

    public function test_bridge_can_write_back_session_completion(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime Intake',
            'enabled' => true,
            'response_mode' => AiAssistantResponseMode::SpeechToSpeech->value,
        ]);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress',
            'mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'bridge_session_token' => 'a-real-token',
            'started_at' => now(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/internal/realtime-bridge/sessions/a-real-token/complete?token=bridge-test-token', [
                'status' => 'completed',
                'transcript' => 'Caller: hi. Assistant: hello!',
                'duration_seconds' => 42,
                'provider_metadata' => ['model' => 'gemini-3.8-live'],
            ]);

        $response->assertOk();
        $session->refresh();
        $this->assertSame('completed', $session->status);
        $this->assertSame('Caller: hi. Assistant: hello!', $session->transcript);
        $this->assertSame(42, $session->duration_seconds);
        $this->assertSame(['model' => 'gemini-3.8-live'], $session->provider_metadata);
        $this->assertNotNull($session->completed_at);
    }
}
