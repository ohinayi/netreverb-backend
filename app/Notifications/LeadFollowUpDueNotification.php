<?php

namespace App\Notifications;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeadFollowUpDueNotification extends Notification
{
    use Queueable;

    public function __construct(public Lead $lead) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'kind' => 'lead_follow_up_due',
            'title' => 'Lead follow-up is due',
            'message' => sprintf(
                'Follow up with %s%s.',
                $this->lead->name,
                $this->lead->company ? ' at '.$this->lead->company : '',
            ),
            'organization' => [
                'id' => $this->lead->organization->public_id,
                'name' => $this->lead->organization->name,
            ],
            'lead' => [
                'id' => $this->lead->public_id,
                'name' => $this->lead->name,
                'company' => $this->lead->company,
            ],
            'follow_up_at' => $this->lead->follow_up_at?->toIso8601String(),
            'url' => '/app/leads',
        ];
    }
}
