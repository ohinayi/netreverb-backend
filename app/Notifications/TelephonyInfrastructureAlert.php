<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TelephonyInfrastructureAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<int, array{key: string, label: string, status: string}>  $unhealthy */
    public function __construct(
        private readonly array $unhealthy,
        private readonly bool $isRecovery,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->isRecovery) {
            return (new MailMessage)
                ->subject('NetReverb telephony: all services recovered')
                ->greeting('All clear.')
                ->line('Every telephony service is reporting healthy again.')
                ->line('Kamailio, FreeSWITCH, RTPengine, and coturn are all active.');
        }

        $message = (new MailMessage)
            ->subject('NetReverb telephony: service(s) down')
            ->greeting('Something needs attention.')
            ->line('The following telephony service(s) are not healthy:');

        foreach ($this->unhealthy as $service) {
            $message->line('- '.$service['label'].': '.$service['status']);
        }

        return $message->line('Calls may be failing or have no audio until this is resolved.');
    }
}
