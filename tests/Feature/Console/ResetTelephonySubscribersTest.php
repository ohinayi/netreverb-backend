<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetTelephonySubscribersTest extends TestCase
{
    public function test_it_clears_all_kamailio_subscribers(): void
    {
        DB::shouldReceive('connection')
            ->once()
            ->with('kamailio')
            ->andReturnSelf();

        DB::shouldReceive('table')
            ->once()
            ->with('subscriber')
            ->andReturnSelf();

        DB::shouldReceive('delete')
            ->once()
            ->andReturn(1);

        $this->artisan('telephony:reset-kamailio-subscribers')
            ->expectsOutput('Deleted 1 Kamailio subscriber row(s).')
            ->assertSuccessful();
    }
}
