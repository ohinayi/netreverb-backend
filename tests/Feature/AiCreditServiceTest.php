<?php

namespace Tests\Feature;

use App\Enums\AiAssistantResponseMode;
use App\Models\AiAssistant;
use App\Models\AiAssistantSession;
use App\Models\Organization;
use App\Services\Ai\AiCreditService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AiCreditServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_has_sufficient_balance_is_false_for_a_zero_or_negative_balance(): void
    {
        $organization = Organization::factory()->create();
        $credits = app(AiCreditService::class);

        $this->assertFalse($credits->hasSufficientBalance($organization));

        $credits->wallet($organization)->update(['balance_units' => -5]);
        $this->assertFalse($credits->hasSufficientBalance($organization));

        $credits->wallet($organization)->update(['balance_units' => 1]);
        $this->assertTrue($credits->hasSufficientBalance($organization));
    }

    public function test_debit_for_session_rounds_up_to_whole_minutes(): void
    {
        $organization = Organization::factory()->create();
        $credits = app(AiCreditService::class);
        $credits->wallet($organization)->update(['balance_units' => 100]);
        $session = $this->makeSession($organization, 61);

        $transaction = $credits->debitForSession($session);

        $this->assertSame(-2, $transaction->units);
        $this->assertSame(98, $credits->wallet($organization)->fresh()->balance_units);
    }

    public function test_debit_for_session_is_idempotent(): void
    {
        $organization = Organization::factory()->create();
        $credits = app(AiCreditService::class);
        $credits->wallet($organization)->update(['balance_units' => 100]);
        $session = $this->makeSession($organization, 90);

        $first = $credits->debitForSession($session);
        $second = $credits->debitForSession($session);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(98, $credits->wallet($organization)->fresh()->balance_units);
    }

    public function test_debit_for_session_allows_the_balance_to_go_negative(): void
    {
        $organization = Organization::factory()->create();
        $credits = app(AiCreditService::class);
        $credits->wallet($organization)->update(['balance_units' => 1]);
        $session = $this->makeSession($organization, 300);

        $transaction = $credits->debitForSession($session);

        $this->assertSame(-5, $transaction->units);
        $this->assertSame(-4, $credits->wallet($organization)->fresh()->balance_units);
    }

    private function makeSession(Organization $organization, int $durationSeconds): AiAssistantSession
    {
        $assistant = AiAssistant::query()->create([
            'organization_id' => $organization->id,
            'name' => 'Test assistant',
            'enabled' => true,
            'response_mode' => AiAssistantResponseMode::SpeechToSpeech->value,
        ]);

        return AiAssistantSession::query()->create([
            'organization_id' => $organization->id,
            'ai_assistant_id' => $assistant->id,
            'status' => 'completed',
            'mode' => AiAssistantResponseMode::SpeechToSpeech->value,
            'duration_seconds' => $durationSeconds,
            'started_at' => now()->subSeconds($durationSeconds),
            'completed_at' => now(),
        ]);
    }
}
