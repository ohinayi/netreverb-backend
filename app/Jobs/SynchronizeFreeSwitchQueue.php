<?php

namespace App\Jobs;

use App\Contracts\Telephony\FreeSwitchQueueGateway;
use App\Models\CallQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SynchronizeFreeSwitchQueue implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $queuePublicId,
        public readonly bool $remove = false,
    ) {
        $this->onQueue('telephony');
    }

    public function handle(FreeSwitchQueueGateway $gateway): void
    {
        $queue = CallQueue::query()->with('extension.dialableNumber', 'members.extension.dialableNumber')
            ->where('public_id', $this->queuePublicId)->first();

        if ($this->remove) {
            $gateway->remove($this->queuePublicId);
            return;
        }

        if ($queue === null || ! $queue->enabled) {
            if ($queue !== null) {
                $gateway->remove('nr_'.$queue->extension->dialableNumber->number.'@default');
            }
            return;
        }

        $gateway->synchronize($queue);
    }
}
