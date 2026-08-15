<?php

namespace Tests\Feature;

use App\Jobs\ExpireStaleAiAssistantSessions;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Organization;
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

        (new ExpireStaleAiAssistantSessions)->handle();

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

        (new ExpireStaleAiAssistantSessions)->handle();

        $this->assertSame('completed', $session->fresh()->status);
    }
}
