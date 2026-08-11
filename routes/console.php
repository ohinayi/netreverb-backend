<?php

use App\Jobs\CleanupCallRecordings;
use App\Jobs\CleanupConferenceRecordings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

Schedule::command('telephony:sync-freeswitch-call-uuids')
    ->everySecond()
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
