<?php

namespace Tests\Feature\Users;

use App\Contracts\Telephony\SipSubscriberGateway;
use App\Models\Extension;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Mockery;
use Tests\TestCase;

class DeleteUserTelephonyCleanupTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_deleting_a_user_removes_the_linked_kamailio_subscriber(): void
    {
        $user = User::factory()->create();
        $extension = Extension::factory()->for($user)->create([
            'display_name' => 'Desk 1',
        ]);

        $gateway = Mockery::mock(SipSubscriberGateway::class);
        $gateway->shouldReceive('delete')
            ->once()
            ->with(
                $extension->dialableNumber->number,
                $extension->dialableNumber->realm,
            );

        $this->app->instance(SipSubscriberGateway::class, $gateway);

        $user->delete();

        $this->assertDatabaseMissing($user->getTable(), [
            'id' => $user->id,
        ]);
    }
}
