<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FreeSwitchCallcenterConfigurationController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $token = (string) config('telephony.freeswitch.xml_curl_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), 403);

        // mod_xml_curl receives every configuration lookup.  Only answer the
        // callcenter lookup and let FreeSWITCH continue to use its local XML
        // files for every other module.
        if ($request->input('key_value') !== 'callcenter.conf') {
            return response('', 404);
        }

        $queues = CallQueue::query()
            ->where('enabled', true)
            ->with([
                'extension.dialableNumber',
                'members' => fn ($query) => $query->where('enabled', true),
            ])
            ->get()
            ->filter(fn (CallQueue $queue): bool => $queue->extension?->dialableNumber !== null);

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $document = $xml->appendChild($xml->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');
        $section = $document->appendChild($xml->createElement('section'));
        $section->setAttribute('name', 'configuration');
        $configuration = $section->appendChild($xml->createElement('configuration'));
        $configuration->setAttribute('name', 'callcenter.conf');
        $configuration->setAttribute('description', 'NetReverb dynamic call queues');
        $configuration->appendChild($xml->createElement('settings'));
        $queuesNode = $configuration->appendChild($xml->createElement('queues'));

        foreach ($queues as $queue) {
            $queueNode = $queuesNode->appendChild($xml->createElement('queue'));
            $queueNode->setAttribute('name', 'nr_'.$queue->extension->dialableNumber->number.'@default');

            // A queue makes one attempt at each enabled member.  The buffer
            // lets the final callback leg finish before mod_callcenter ends
            // the waiting caller instead of starting another pass.
            $memberCount = $queue->members->count();
            $singlePassWait = $memberCount > 0
                ? ($memberCount * $queue->agent_ring_timeout_seconds) + 5
                : 5;
            $maxWait = min($queue->max_wait_seconds, $singlePassWait);

            foreach ([
                'strategy' => $queue->strategy,
                'moh-sound' => 'local_stream://moh',
                'time-base-score' => 'system',
                'max-wait-time' => (string) $maxWait,
                'max-wait-time-with-no-agent' => '5',
                'max-wait-time-with-no-agent-time-reached' => '5',
                'tier-rules-apply' => 'false',
                'tier-rule-wait-second' => '300',
                'tier-rule-wait-multiply-level' => 'true',
                'tier-rule-no-agent-no-wait' => 'false',
                'discard-abandoned-after' => '60',
                'abandoned-resume-allowed' => 'false',
            ] as $name => $value) {
                $param = $queueNode->appendChild($xml->createElement('param'));
                $param->setAttribute('name', $name);
                $param->setAttribute('value', $value);
            }
        }

        return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }
}
