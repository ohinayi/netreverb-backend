<?php

namespace Tests\Feature;

use App\Contracts\Ai\AudioTranscriptionProvider;
use App\Contracts\Ai\StructuredAssistantProvider;
use App\Enums\ServiceNumberType;
use App\Models\AiAssistant;
use App\Models\AiAssistantField;
use App\Models\AiAssistantSession;
use App\Models\Organization;
use App\Models\ServiceNumber;
use App\Services\Telephony\AiAssistantCallFlow;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiAssistantLiveCallFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function allowXmlCurl(): void
    {
        config()->set('telephony.freeswitch.xml_curl_token', 'test-token');
        config()->set('telephony.freeswitch.xml_curl_allowed_ips', ['127.0.0.1']);
    }

    /**
     * Binds fake transcription/extraction providers backed by shared,
     * mutable state - the returned object's ->transcript/->value can be
     * changed between requests within one test. Laravel's router caches
     * the resolved controller (and therefore its injected providers) on
     * the Route object across dispatches within a single test method, so
     * rebinding the container mid-test via app()->instance() again would
     * silently have no effect on later requests; real production requests
     * don't share this caching since each is a fresh process.
     */
    private function fakeAiProviders(string $transcript = 'my name is Abdul', ?string $extractedValue = 'Abdul'): object
    {
        $state = (object) ['transcript' => $transcript, 'value' => $extractedValue, 'lastInstruction' => null];

        $this->app->instance(AudioTranscriptionProvider::class, new class($state) implements AudioTranscriptionProvider
        {
            public function __construct(private readonly object $state) {}

            public function transcribe(string $disk, string $path): string
            {
                return $this->state->transcript;
            }
        });

        $this->app->instance(StructuredAssistantProvider::class, new class($state) implements StructuredAssistantProvider
        {
            public function __construct(private readonly object $state) {}

            public function extract(string $instruction, string $transcript, array $schema): array
            {
                $this->state->lastInstruction = $instruction;
                $key = array_key_first($schema['properties'] ?? []);

                return $key ? [$key => $this->state->value] : [];
            }
        });

        return $state;
    }

    private function buildAssistant(Organization $organization): AiAssistant
    {
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id, 'name' => 'Intake', 'enabled' => true,
            'welcome_message' => 'Welcome to Net Core.', 'closing_message' => 'Thanks for calling Net Core, bye now.',
        ]);
        AiAssistantField::query()->create(['ai_assistant_id' => $assistant->id, 'key' => 'name', 'label' => 'Name', 'question' => 'What is your name?', 'sort_order' => 0]);
        AiAssistantField::query()->create(['ai_assistant_id' => $assistant->id, 'key' => 'email', 'label' => 'Email', 'question' => 'What is your email?', 'sort_order' => 1]);

        return $assistant->load('fields');
    }

    private function serviceNumberFor(AiAssistant $assistant): ServiceNumber
    {
        return ServiceNumber::factory()->for($assistant->organization)->create([
            'type' => ServiceNumberType::Assistant,
            'configuration' => ['ai_assistant_id' => $assistant->public_id],
        ])->load('dialableNumber');
    }

    private function putFakeRecording(AiAssistantSession $session, string $fieldKey): void
    {
        Storage::disk('ai_assistant_recordings')->put("answers-{$session->public_id}-{$fieldKey}-{$session->retry_count}.wav", str_repeat('x', 200));
    }

    public function test_dialing_an_assistant_service_number_starts_a_session_and_asks_the_first_field(): void
    {
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = $this->buildAssistant($organization);
        $service = $this->serviceNumberFor($assistant);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&destination_number='.$service->dialableNumber->number
        );

        $response->assertOk();
        $xml = $response->getContent();
        $this->assertStringContainsString('Welcome to Net Core.', $xml);
        $this->assertStringContainsString('What is your name?', $xml);
        $this->assertStringContainsString('application="record"', $xml);
        $this->assertStringContainsString(AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX, $xml);

        $session = AiAssistantSession::query()->sole();
        $this->assertSame('in_progress', $session->status);
        $this->assertSame('name', $session->current_field_key);
    }

    public function test_live_extraction_includes_the_assistants_own_instructions(): void
    {
        Storage::fake('ai_assistant_recordings');
        $this->allowXmlCurl();
        $aiState = $this->fakeAiProviders('the spicy one', 'Spicy Chicken');
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id, 'name' => 'Orders', 'enabled' => true,
            'system_instruction' => 'Menu: Spicy Chicken, Plain Chicken, Beef Suya.',
        ]);
        AiAssistantField::query()->create(['ai_assistant_id' => $assistant->id, 'key' => 'item', 'label' => 'Item', 'question' => 'What would you like?', 'sort_order' => 0]);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress', 'current_field_key' => 'item', 'started_at' => now(),
        ]);
        $this->putFakeRecording($session, 'item');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX.$session->public_id
        )->assertOk();

        $this->assertStringContainsString('Menu: Spicy Chicken, Plain Chicken, Beef Suya.', $aiState->lastInstruction);
    }

    public function test_full_two_field_flow_completes_and_captures_both_answers(): void
    {
        Storage::fake('ai_assistant_recordings');
        $this->allowXmlCurl();
        $aiState = $this->fakeAiProviders('my name is Abdul', 'Abdul');
        $organization = Organization::factory()->create();
        $assistant = $this->buildAssistant($organization);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress', 'current_field_key' => 'name', 'started_at' => now(),
        ]);
        $this->putFakeRecording($session, 'name');

        $answer = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX.$session->public_id
        )->assertOk();
        $this->assertStringContainsString('Abdul', $answer->getContent());
        $this->assertStringContainsString(AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX, $answer->getContent());

        $confirm = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX.$session->public_id.'&destination_number=1'
        )->assertOk();
        $this->assertStringContainsString('What is your email?', $confirm->getContent());
        $session->refresh();
        $this->assertSame(['name' => 'Abdul'], $session->captured_data);
        $this->assertSame('email', $session->current_field_key);
        $this->assertSame('in_progress', $session->status);

        $aiState->transcript = 'abdul at example dot com';
        $aiState->value = 'abdul@example.com';
        $this->putFakeRecording($session, 'email');
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX.$session->public_id
        )->assertOk();
        $finalConfirm = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX.$session->public_id.'&destination_number=1'
        )->assertOk();

        $this->assertStringContainsString('application="hangup"', $finalConfirm->getContent());
        $this->assertStringContainsString('Thanks for calling Net Core, bye now.', $finalConfirm->getContent());
        $session->refresh();
        $this->assertSame(['name' => 'Abdul', 'email' => 'abdul@example.com'], $session->captured_data);
        $this->assertSame('completed', $session->status);
        $this->assertNotNull($session->completed_at);
    }

    public function test_pressing_redo_re_asks_the_same_field_and_increments_retry_count(): void
    {
        Storage::fake('ai_assistant_recordings');
        $this->allowXmlCurl();
        $organization = Organization::factory()->create();
        $assistant = $this->buildAssistant($organization);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress', 'current_field_key' => 'name', 'pending_value' => 'Abdul', 'started_at' => now(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX.$session->public_id.'&destination_number=2'
        )->assertOk();

        $this->assertStringContainsString('What is your name?', $response->getContent());
        $session->refresh();
        $this->assertSame(1, $session->retry_count);
        $this->assertNull($session->pending_value);
        $this->assertSame('name', $session->current_field_key);
        $this->assertNull($session->captured_data);
    }

    public function test_an_empty_transcript_triggers_a_redo_without_calling_the_extraction_provider(): void
    {
        Storage::fake('ai_assistant_recordings');
        $this->allowXmlCurl();
        $this->fakeAiProviders('', 'should-not-be-used');
        $organization = Organization::factory()->create();
        $assistant = $this->buildAssistant($organization);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress', 'current_field_key' => 'name', 'started_at' => now(),
        ]);
        $this->putFakeRecording($session, 'name');

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX.$session->public_id
        )->assertOk();

        $this->assertStringNotContainsString(AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX, $response->getContent());
        $this->assertStringContainsString('What is your name?', $response->getContent());
        $session->refresh();
        $this->assertSame(1, $session->retry_count);
    }

    public function test_exhausting_retries_skips_the_field_with_a_null_value_and_moves_on(): void
    {
        Storage::fake('ai_assistant_recordings');
        $this->allowXmlCurl();
        config()->set('telephony.ai_assistant.max_retries', 1);
        $organization = Organization::factory()->create();
        $assistant = $this->buildAssistant($organization);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress', 'current_field_key' => 'name', 'pending_value' => 'Abdul',
            'retry_count' => 1, 'started_at' => now(),
        ]);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->get(
            '/api/freeswitch/dialplan.xml?token=test-token&context='.AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX.$session->public_id.'&destination_number=2'
        )->assertOk();

        $this->assertStringContainsString('What is your email?', $response->getContent());
        $session->refresh();
        $this->assertSame(['name' => null], $session->captured_data);
        $this->assertSame(0, $session->retry_count);
        $this->assertSame('email', $session->current_field_key);
    }
}
