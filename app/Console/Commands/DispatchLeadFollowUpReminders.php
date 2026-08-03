<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Notifications\LeadFollowUpDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchLeadFollowUpReminders extends Command
{
    protected $signature = 'leads:dispatch-follow-up-reminders';

    protected $description = 'Create deduplicated in-app reminders for due lead follow-ups';

    public function handle(): int
    {
        $dispatched = 0;

        Lead::query()
            ->whereNotNull('assigned_user_id')
            ->whereNotNull('follow_up_at')
            ->whereNull('follow_up_notified_at')
            ->whereNull('follow_up_completed_at')
            ->where('follow_up_at', '<=', now())
            ->whereNotIn('status', ['won', 'lost'])
            ->select('id')
            ->chunkById(100, function ($leads) use (&$dispatched): void {
                foreach ($leads as $candidate) {
                    DB::transaction(function () use ($candidate, &$dispatched): void {
                        $lead = Lead::query()
                            ->with(['organization', 'assignedUser'])
                            ->lockForUpdate()
                            ->find($candidate->id);

                        if (
                            ! $lead
                            || ! $lead->assignedUser
                            || ! $lead->follow_up_at
                            || $lead->follow_up_at->isFuture()
                            || $lead->follow_up_notified_at
                            || $lead->follow_up_completed_at
                            || in_array($lead->status, ['won', 'lost'], true)
                        ) {
                            return;
                        }

                        $lead->assignedUser->notifyNow(new LeadFollowUpDueNotification($lead));
                        $lead->forceFill(['follow_up_notified_at' => now()])->save();
                        $dispatched++;
                    });
                }
            });

        $this->info("Dispatched {$dispatched} lead follow-up reminder(s).");

        return self::SUCCESS;
    }
}
