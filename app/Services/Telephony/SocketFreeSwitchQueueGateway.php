<?php

namespace App\Services\Telephony;

use App\Contracts\Telephony\FreeSwitchQueueGateway;
use App\Models\CallQueue;
use RuntimeException;

class SocketFreeSwitchQueueGateway implements FreeSwitchQueueGateway
{
    public function __construct(private readonly FreeSwitchEventSocketClient $client) {}

    public function synchronize(CallQueue $queue): void
    {
        $queue->loadMissing('extension.dialableNumber', 'members.extension.dialableNumber');
        $name = $this->queueName($queue);

        /*
         * mod_callcenter does not expose a "queue add" or "queue set" API.
         * Queue settings are read from callcenter.conf.xml and a queue is made
         * live with `queue load`. Agents and tiers, on the other hand, are
         * intentionally managed through the API below.
         */
        $this->run("callcenter_config queue load {$name}", acceptExisting: true);
        $this->run("callcenter_config queue reload {$name}", acceptExisting: true);

        foreach ($this->existingAgents($name) as $agent) {
            $this->run("callcenter_config tier del {$name} {$agent}");
        }

        foreach ($queue->members->where('enabled', true)->values() as $position => $member) {
            $extension = $member->extension;
            if ($extension === null || $extension->dialableNumber === null) {
                continue;
            }

            $agent = $this->agentName($queue, $extension->dialableNumber->number);
            $contact = sprintf(
                '[absolute_codec_string=OPUS,leg_timeout=%d,ignore_early_media=true]sofia/external/%s@%s:%d',
                $queue->agent_ring_timeout_seconds,
                $extension->dialableNumber->number,
                config('telephony.sip_server'),
                config('telephony.sip_port'),
            );
            $this->run("callcenter_config agent add {$agent} callback", acceptExisting: true);
            $this->run("callcenter_config agent set contact {$agent} {$contact}");
            $this->run("callcenter_config agent set status {$agent} Available");
            $this->run(sprintf(
                'callcenter_config tier add %s %s %d %d',
                $name,
                $agent,
                $member->priority,
                $position + 1,
            ));
        }
    }

    public function remove(string $queueName): void
    {
        // A queue definition belongs to callcenter.conf.xml, where it is
        // provisioned.  Unloading it stops accepting calls without trying to
        // use the unsupported `queue del` command.
        $this->run("callcenter_config queue unload {$queueName}", acceptMissing: true);
    }

    private function existingAgents(string $queueName): array
    {
        $response = $this->client->api("callcenter_config tier list {$queueName}");
        if (! str_contains($response, '+OK')) {
            return [];
        }

        return collect(preg_split('/\R/', $response) ?: [])
            ->filter(fn (string $line): bool => str_starts_with($line, $queueName.'|'))
            ->map(fn (string $line): ?string => explode('|', $line)[1] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function queueName(CallQueue $queue): string
    {
        return 'nr_'.$queue->extension->dialableNumber->number.'@default';
    }

    private function agentName(CallQueue $queue, string $number): string
    {
        return 'nr_'.$queue->extension->dialableNumber->number.'_'.$number;
    }

    private function run(string $command, bool $acceptExisting = false, bool $acceptMissing = false): void
    {
        $response = $this->client->api($command);
        if (str_contains($response, '+OK')) {
            return;
        }
        if ($acceptExisting && str_contains(strtolower($response), 'already exist')) {
            return;
        }
        if ($acceptMissing && str_contains(strtolower($response), 'not found')) {
            return;
        }

        throw new RuntimeException(sprintf(
            'FreeSWITCH queue configuration failed for [%s]: %s',
            $command,
            trim($response),
        ));
    }
}
