<?php

namespace Tests\Unit\Jobs;

use App\Actions\Extensions\CreateExtension;
use App\Contracts\Telephony\SipSubscriberGateway;
use App\Data\SipSubscriber;
use App\Enums\ExtensionStatus;
use App\Enums\ProvisioningEventStatus;
use App\Enums\ProvisioningStatus;
use App\Jobs\ProvisionSipSubscriber;
use App\Models\Organization;
use App\Models\SipProvisioningEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class ProvisionSipSubscriberTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_job_projects_the_subscriber_and_marks_the_revision_active(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $result = app(CreateExtension::class)->execute($organization, [
            'number' => '45105',
            'display_name' => 'Provisioned Desk',
            'type' => 'user',
        ]);
        $event = SipProvisioningEvent::query()->sole();
        $gateway = Mockery::mock(SipSubscriberGateway::class);
        $gateway->shouldReceive('upsert')->once()->with(Mockery::on(
            fn (SipSubscriber $subscriber): bool => $subscriber->username === '45105'
                && $subscriber->realm === 'sip.classyra.com.ng'
                && $subscriber->password === $result->sipPassword,
        ));

        (new ProvisionSipSubscriber($event->id))->handle($gateway);

        $this->assertSame(ProvisioningEventStatus::Completed, $event->refresh()->status);
        $this->assertSame(ProvisioningStatus::Active, $result->extension->provisioningState->refresh()->status);
        $this->assertSame(1, $result->extension->provisioningState->applied_revision);
        $this->assertSame(ExtensionStatus::Active, $result->extension->refresh()->status);
    }

    public function test_completed_event_is_idempotent(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        app(CreateExtension::class)->execute($organization, [
            'number' => '45106',
            'display_name' => 'Idempotent Desk',
            'type' => 'user',
        ]);
        $event = SipProvisioningEvent::query()->sole();
        $event->update(['status' => ProvisioningEventStatus::Completed]);
        $gateway = Mockery::mock(SipSubscriberGateway::class);
        $gateway->shouldNotReceive('upsert');
        $gateway->shouldNotReceive('delete');

        (new ProvisionSipSubscriber($event->id))->handle($gateway);

        $this->assertSame(ProvisioningEventStatus::Completed, $event->refresh()->status);
    }
}
