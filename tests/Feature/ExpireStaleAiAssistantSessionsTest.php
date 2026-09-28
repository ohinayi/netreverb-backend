<?php

namespace Tests\Feature;

use App\Enums\AiAssistantResponseMode;
use App\Jobs\ExpireStaleAiAssistantSessions;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Organization;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ExpireStaleAiAssistantSessionsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_fails_an_in_progress_session_that_has_gone_quiet_too_long(): void
    {
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Orders', 'enabled' => true]);
        $stale = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id, 'status' => 'in_progress',
        ]);
        $stale->timestamps = false;
        $stale->forceFill(['updated_at' => now()->subMinutes(15)])->save();
        $fresh = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id, 'status' => 'in_progress',
        ]);

        app()->call([new ExpireStaleAiAssistantSessions, 'handle']);

        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertSame('in_progress', $fresh->fresh()->status);
    }

    public function test_it_leaves_already_completed_sessions_alone(): void
    {
        $organization = Organization::factory()->create();
        $assistant = AiAssistant::query()->create(['organization_id' => $organization->id, 'name' => 'Orders', 'enabled' => true]);
        $session = AiAssistantSession::query()->create([
            'organization_id' => $organization->id, 'ai_assistant_id' => $assistant->id, 'status' => 'completed',
        ]);
        $session->timestamps = false;
        $session->forceFill(['updated_at' => now()->subMinutes(15)])->save();

        app()->call([new ExpireStaleAiAssistantSessions, 'handle']);

        $this->assertSame('completed', $session->fresh()->status);
    }

    public function test_it_bills_a_stale_speech_to_speech_session_for_elapsed_time_capped_at_ten_minutes(): void
    {
        $organization = Organization::factory()->create();
        $credits = app(AiCreditService::class);
        $credits->wallet($organization)->update(['balance_units' => 100]);
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Realtime',
            'enabled' => true,
            'response_mode' => AiAssistantResponseMode::SpeechToSpeech->value,
        ]);
        $stale = AiAssistantSession::query()->create([
            'organization_id' => $organization->id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'in_progress',
            'mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'started_at' => now()->subHours(2),
        ]);
        $stale->timestamps = false;
        $stale->forceFill(['updated_at' => now()->subMinutes(15)])->save();

        app()->call([new ExpireStaleAiAssistantSessions, 'handle']);

        // Elapsed time from started_at is capped at this job's own
        // 10-minute staleness threshold, not the full 2 hours since
        // started_at - so 10 units (minutes) are billed, not 120.
        $this->assertSame(90, $credits->wallet($organization)->fresh()->balance_units);
    }
}
