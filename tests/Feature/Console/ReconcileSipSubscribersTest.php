<?php

namespace Tests\Feature\Console;

use App\Actions\Extensions\CreateExtension;
use App\Enums\ProvisioningEventStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\Organization;
use App\Models\SipProvisioningEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileSipSubscribersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_reconcile_dispatches_a_failed_provisioning_event_again(): void
    {
        Queue::fake();
        app(CreateExtension::class)->execute(Organization::factory()->create(), [
            'number' => '45109',
            'display_name' => 'Reconcile Desk',
            'type' => 'user',
        ]);
        $event = SipProvisioningEvent::query()->sole();
        $event->update(['status' => ProvisioningEventStatus::Failed]);
        Queue::fake();

        $this->artisan('sip:reconcile')
            ->expectsOutput('Dispatched 1 SIP provisioning event(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            ProvisionSipSubscriber::class,
            fn (ProvisionSipSubscriber $job): bool => $job->eventId === $event->id,
        );
    }
}
