<?php

use App\Jobs\CleanupCallRecordings;
use App\Jobs\CleanupConferenceRecordings;
use App\Jobs\ExpireStaleAiAssistantSessions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// telephony:sync-freeswitch-call-uuids does NOT run here. Scheduling it
// everySecond() forked a brand-new bootstrapped PHP process every single
// second, forever, regardless of call activity - measured pegging a 4-core
// prod box to load ~4.5-4.9 with zero active calls. It runs instead as one
// persistent `--watch` process under supervisor (prod) / concurrently
// (local dev), looping on FreeSWITCH events internally instead of
// re-bootstrapping Laravel every tick. See TELEPHONY_IVR_RUNBOOK.md for the
// supervisor config.
Schedule::command('sip:reconcile')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('conference-rooms:expire')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('conference-rooms:reconcile-participants')
    ->everyThirtySeconds()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('conference-rooms:reconcile-presence')
    ->everyTenSeconds()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('conference-recordings:sync-tracks')
    ->everyTenSeconds()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::job(new CleanupConferenceRecordings)
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::job(new CleanupCallRecordings)
    ->daily()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::job(new ExpireStaleAiAssistantSessions)
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('leads:dispatch-follow-up-reminders')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('outbound:dispatch-due-campaigns')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('payments:reconcile-sms-credit')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('telephony:check-infrastructure-health')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
