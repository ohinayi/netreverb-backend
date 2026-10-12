<?php

namespace App\Notifications;

use App\Models\WebsiteChatSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WebsiteLeadCaptured extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly WebsiteChatSession $session) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('New lead from Lisa (website chat)')
            ->greeting('Someone on the website left their contact info with Lisa.')
            ->line('Name: '.($this->session->lead_name ?: 'not given'))
            ->line('Contact: '.($this->session->lead_contact ?: 'not given'));

        if (filled($this->session->lead_need)) {
            $message->line('What they want: '.$this->session->lead_need);
        }

        return $message->line('Full transcript is on the WebsiteChatSession record ('.$this->session->public_id.').');
    }
}
