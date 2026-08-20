<?php

namespace App\Http\Controllers;

use App\Enums\ServiceNumberType;
use App\Models\AiAssistant;
use App\Models\ConferenceRoom;
use App\Models\OrganizationIvr;
use App\Models\OrganizationIvrOption;
use App\Models\ServiceNumber;
use App\Services\Telephony\AiAssistantCallFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FreeSwitchDialplanController extends Controller
{
    public function __construct(private readonly AiAssistantCallFlow $aiAssistantCallFlow) {}

    public function __invoke(Request $request)
    {
        $token = (string) config('telephony.freeswitch.xml_curl_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->query('token')), 403);
        abort_unless(in_array($request->ip(), config('telephony.freeswitch.xml_curl_allowed_ips', []), true), 403);

        // FreeSWITCH's XML-cURL POST for a dialplan lookup never includes a
        // bare "context"/"destination_number" field — only the caller
        // profile's prefixed "Caller-Context"/"Caller-Destination-Number"
        // (confirmed from a live capture). Reading the bare field alone
        // always fell back to the 'public' default, silently ignoring the
        // real context (e.g. the private ivr-options-<id> context used for
        // an IVR digit transfer) and misrouting DTMF presses.
        $contextName = (string) ($request->input('context') ?: $request->input('Caller-Context') ?: 'public');

        // `transfer <digit> XML ivr-options-<public_id>` triggers a brand
        // new XML-cURL request from FreeSWITCH — it does not reuse the
        // multi-context document returned for the original call. That
        // request's destination_number is just the pressed digit (e.g. "1"),
        // which is not a service number, so it must be handled here before
        // falling into the generic numeric-extension fallback below;
        // otherwise the digit gets treated as if someone dialed extension
        // "1" and the call ends with NO_ROUTE_DESTINATION.
        if (str_starts_with($contextName, 'ivr-options-')) {
            return $this->ivrOptionResponse($request, $contextName);
        }
        if (str_starts_with($contextName, AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX)) {
            return $this->aiAssistantCallFlow->handleAnswer($request, $contextName);
        }
        if (str_starts_with($contextName, AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX)) {
            return $this->aiAssistantCallFlow->handleConfirm($request, $contextName);
        }
        if (str_starts_with($contextName, AiAssistantCallFlow::DTMF_CONTEXT_PREFIX)) {
            return $this->aiAssistantCallFlow->handleDtmfAnswer($request, $contextName);
        }

        $number = (string) ($request->input('destination_number') ?: $request->input('Caller-Destination-Number'));

        // Conference rooms are dynamically allocated DialableNumbers, not
        // ServiceNumbers, so the lookup below never finds them - without this
        // branch they fell into the generic numeric-extension bridge further
        // down, which re-sends the call out to Kamailio for a number nobody
        // is registered as, and Kamailio hands it straight back in. That
        // round-trip repeats until Max-Forwards is exhausted (SIP 483 "Too
        // Many Hops"), which is why joining a conference room hung on
        // "connecting..." and then dropped.
        $conferenceRoom = ConferenceRoom::query()->where('sip_number', $number)->first();
        if ($conferenceRoom) {
            $xml = new \DOMDocument('1.0', 'UTF-8');
            $document = $xml->appendChild($xml->createElement('document'));
            $document->setAttribute('type', 'freeswitch/xml');
            $section = $document->appendChild($xml->createElement('section'));
            $section->setAttribute('name', 'dialplan');
            $context = $section->appendChild($xml->createElement('context'));
            $context->setAttribute('name', $contextName);

            $extension = $context->appendChild($xml->createElement('extension'));
            $extension->setAttribute('name', 'conference-'.$number);
            $condition = $extension->appendChild($xml->createElement('condition'));
            $condition->setAttribute('field', 'destination_number');
            $condition->setAttribute('expression', '^'.preg_quote($number, '/').'$');
            $answer = $condition->appendChild($xml->createElement('action'));
            $answer->setAttribute('application', 'answer');
            $conferenceAction = $condition->appendChild($xml->createElement('action'));
            $conferenceAction->setAttribute('application', 'conference');
            $conferenceAction->setAttribute('data', $number.'@default');

            return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }

        $service = ServiceNumber::query()->where('enabled', true)->whereHas('dialableNumber', fn ($q) => $q->where('number', $number))->first();
        $ivrId = data_get($service?->configuration, 'ivr_public_id');
        $ivr = $ivrId ? $service?->organization?->ivrs()->with('options')->where('public_id', $ivrId)->where('enabled', true)->first() : null;
        $assistantId = $service?->type === ServiceNumberType::Assistant ? data_get($service->configuration, 'ai_assistant_id') : null;
        $assistant = $assistantId
            ? AiAssistant::query()->with('fields')->where('organization_id', $service->organization_id)->where('public_id', $assistantId)->where('enabled', true)->first()
            : null;

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $document = $xml->appendChild($xml->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');
        $section = $document->appendChild($xml->createElement('section'));
        $section->setAttribute('name', 'dialplan');
        $context = $section->appendChild($xml->createElement('context'));
        $context->setAttribute('name', $contextName);

        // XML-cURL is also queried for ordinary extensions. Return a direct
        // external-profile bridge for numeric extensions so local testing does
        // not depend on the VPS-only Lua dialplan. Invalid/non-numeric values
        // still fall through to FreeSWITCH's normal dialplan.
        if (! $service) {
            if (! preg_match('/^\d+$/', $number)) {
                return response('', 404);
            }

            $extension = $context->appendChild($xml->createElement('extension'));
            $extension->setAttribute('name', 'extension-'.$number);
            $extensionCondition = $extension->appendChild($xml->createElement('condition'));
            $extensionCondition->setAttribute('field', 'destination_number');
            $extensionCondition->setAttribute('expression', '^'.preg_quote($number, '/').'$');
            $bridge = $extensionCondition->appendChild($xml->createElement('action'));
            $bridge->setAttribute('application', 'bridge');
            $bridge->setAttribute('data', sprintf(
                'sofia/external/%s@%s:%d',
                $number,
                config('telephony.sip_server'),
                (int) config('telephony.sip_port'),
            ));

            return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }

        $extension = $context->appendChild($xml->createElement('extension'));
        $extension->setAttribute('name', 'ivr-'.$number);
        $condition = $extension->appendChild($xml->createElement('condition'));
        $condition->setAttribute('field', 'destination_number');
        $condition->setAttribute('expression', '^'.preg_quote($number, '/').'$');
        $actions = $condition->appendChild($xml->createElement('action'));
        $actions->setAttribute('application', 'answer');

        if ($ivr) {
            $options = $this->appendMenuActions($xml, $condition, $ivr);
            if ($options) {
                // Keep the menu choices out of the public dialplan. A public
                // context contains other catch-all routes, so resolving `1`
                // there can select an unrelated extension instead of this
                // IVR's first choice. This copy is a harmless bonus in case
                // FreeSWITCH ever reuses the cached document instead of
                // re-fetching; ivrOptionResponse() below is what actually
                // serves the `transfer ... XML ivr-options-...` re-fetch.
                $optionsContext = $section->appendChild($xml->createElement('context'));
                $optionsContext->setAttribute('name', $this->optionsContextName($ivr));
                $this->appendOptionExtensions($xml, $optionsContext, $section, $options);
            }
        } elseif ($assistant) {
            $this->aiAssistantCallFlow->emitEntry($xml, $condition, $assistant);
        } elseif ($assistantId || ($ivrId && ! $ivr)) {
            // The service number is wired to an assistant or IVR that's
            // since been disabled or deleted. Say so instead of leaving the
            // caller in dead air - the flow never even started, so there's
            // no in-progress call to fail partway through.
            $unavailable = $condition->appendChild($xml->createElement('action'));
            $unavailable->setAttribute('application', 'speak');
            $unavailable->setAttribute('data', 'flite|slt|This service is temporarily unavailable. Please try again later.');
            $hangup = $condition->appendChild($xml->createElement('action'));
            $hangup->setAttribute('application', 'hangup');
            $hangup->setAttribute('data', 'NORMAL_CLEARING');
        } elseif ($service?->target) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'bridge');
            // Browser extensions register externally through Kamailio, not in
            // FreeSWITCH's own directory, so `user/<target>` cannot find them
            // (this is the same fix already applied to IVR option bridging).
            $action->setAttribute('data', sprintf(
                'sofia/external/%s@%s:%d',
                str_replace('|', '', $service->target),
                config('telephony.sip_server'),
                (int) config('telephony.sip_port'),
            ));
        }

        return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    /**
     * Serves the dedicated XML-cURL re-fetch FreeSWITCH performs when it
     * executes `transfer <digit> XML ivr-options-<public_id>`. The request's
     * destination_number is the pressed digit, not the service number, so
     * this looks the IVR up by the public_id embedded in the context name.
     */
    private function ivrOptionResponse(Request $request, string $contextName)
    {
        $digit = (string) ($request->input('destination_number') ?: $request->input('Caller-Destination-Number'));
        $publicId = substr($contextName, strlen('ivr-options-'));
        $ivr = OrganizationIvr::query()->with('options')->where('public_id', $publicId)->where('enabled', true)->first();

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $document = $xml->appendChild($xml->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');
        $section = $document->appendChild($xml->createElement('section'));
        $section->setAttribute('name', 'dialplan');
        $context = $section->appendChild($xml->createElement('context'));
        $context->setAttribute('name', $contextName);

        if (! $ivr) {
            return response($xml->saveXML(), 404, ['Content-Type' => 'text/xml; charset=UTF-8']);
        }

        $options = $ivr->options()->where('enabled', true)->where('digit', $digit)->orderBy('sort_order')->get();
        $this->appendOptionExtensions($xml, $context, $section, $options);

        return response($xml->saveXML(), 200, ['Content-Type' => 'text/xml; charset=UTF-8']);
    }

    private function optionsContextName(OrganizationIvr $ivr): string
    {
        return 'ivr-options-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) $ivr->public_id);
    }

    /**
     * Plays an IVR's welcome prompt and, if it has enabled options, reads one
     * digit and transfers to its options context. Used both for the initial
     * inbound call and for a `submenu` option nested inside another IVR -
     * the two cases are otherwise identical.
     *
     * @return Collection<int, OrganizationIvrOption>|null the
     *                                                     IVR's enabled options, for the caller to (optionally) embed as a
     *                                                     bonus inline context - or null if it has none.
     */
    private function appendMenuActions(\DOMDocument $xml, \DOMElement $condition, OrganizationIvr $ivr): ?Collection
    {
        // Give the caller a short moment before speech starts; this avoids
        // clipping the first syllables on SIP/WebRTC endpoints that are
        // still opening their audio element.
        $pause = $condition->appendChild($xml->createElement('action'));
        $pause->setAttribute('application', 'sleep');
        $pause->setAttribute('data', '1500');

        $generatedPrompt = $ivr->welcome_audio_path
            && str_ends_with($ivr->welcome_audio_path, '/'.$ivr->public_id.'.wav');
        if ($ivr->welcome_audio_path) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'playback');
            $audioBaseUrl = (string) config('telephony.freeswitch.ivr_audio_base_url', '');
            $audioPath = $audioBaseUrl !== ''
                ? $audioBaseUrl.'/storage/'.ltrim($ivr->welcome_audio_path, '/')
                : storage_path('app/public/'.$ivr->welcome_audio_path);
            $action->setAttribute('data', $audioPath);
        } elseif ($ivr->welcome_text) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'speak');
            // FreeSWITCH's speak application expects the TTS engine first,
            // followed by the voice and text.  `en|...` is not an engine
            // and causes the channel to fail before the caller hears the
            // prompt when mod_flite is the installed provider.
            $action->setAttribute('data', 'flite|slt|'.str_replace('|', ' ', $ivr->welcome_text));
        }

        $options = $ivr->options()->where('enabled', true)->orderBy('sort_order')->get();
        if ($options->isEmpty()) {
            return null;
        }

        if (! $generatedPrompt) {
            $menuText = ' Please choose an option. '.$options
                ->map(fn ($option): string => 'Press '.$option->digit.' for '.$option->label.'.')
                ->implode(' ');
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'speak');
            $action->setAttribute('data', 'flite|slt|'.str_replace('|', ' ', $menuText));
        }

        // Read the digit once. The previous implementation appended a
        // read/execute pair for every option, which immediately
        // executed option 1 and then option 2 and ended the call.
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'read');
        // read syntax: min, max, prompt, variable, timeout(ms),
        // terminator. The previous order made FreeSWITCH interpret
        // the timeout ("5") as a sound filename (5.wav).
        $action->setAttribute('data', '1 1 silence_stream://1000 ivr_digit '.max(10000, ((int) $ivr->timeout_seconds * 1000)).' #');
        $action = $condition->appendChild($xml->createElement('action'));
        $action->setAttribute('application', 'transfer');
        $action->setAttribute('data', '${ivr_digit} XML '.$this->optionsContextName($ivr));

        return $options;
    }

    private function appendDirectivePlayback(\DOMDocument $xml, \DOMElement $condition, OrganizationIvrOption $option): void
    {
        if ($option->directive_audio_path) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'playback');
            $audioBaseUrl = (string) config('telephony.freeswitch.ivr_audio_base_url', '');
            $audioPath = $audioBaseUrl !== ''
                ? $audioBaseUrl.'/storage/'.ltrim($option->directive_audio_path, '/')
                : storage_path('app/public/'.$option->directive_audio_path);
            $action->setAttribute('data', $audioPath);

            return;
        }

        if ($option->directive_text) {
            $action = $condition->appendChild($xml->createElement('action'));
            $action->setAttribute('application', 'speak');
            $action->setAttribute('data', 'flite|slt|'.str_replace('|', ' ', (string) $option->directive_text));
        }
    }

    /** @param Collection<int, OrganizationIvrOption> $options */
    private function appendOptionExtensions(\DOMDocument $xml, \DOMElement $context, \DOMElement $section, $options): void
    {
        // Create an exact route for each key press in the IVR's own
        // context. This prevents one option from matching every digit.
        foreach ($options as $option) {
            $digitExtension = $context->appendChild($xml->createElement('extension'));
            $digitExtension->setAttribute('name', (string) $option->digit);
            $digitCondition = $digitExtension->appendChild($xml->createElement('condition'));
            $digitCondition->setAttribute('field', 'destination_number');
            $digitCondition->setAttribute('expression', '^'.preg_quote((string) $option->digit, '/').'$');
            $type = (string) $option->destination_type;
            $destination = (string) ($option->destination ?? '');

            if ($type === 'submenu') {
                // The child IVR's own options context (e.g.
                // ivr-options-<child_public_id>) is served the normal way,
                // by FreeSWITCH re-fetching this same endpoint once the
                // caller presses a digit - so it does not need to be (and
                // deliberately is not) embedded here. That also means a
                // submenu that loops back to an ancestor menu can never
                // cause runaway recursion while building this XML document;
                // it just becomes a caller-navigable loop, same as any real
                // "press 9 to go back" IVR.
                $child = OrganizationIvr::query()->where('public_id', $destination)->where('enabled', true)->first();
                if ($child) {
                    $this->appendMenuActions($xml, $digitCondition, $child);
                } else {
                    $action = $digitCondition->appendChild($xml->createElement('action'));
                    $action->setAttribute('application', 'hangup');
                    $action->setAttribute('data', 'NORMAL_CLEARING');
                }

                continue;
            }

            if ($type === 'directive') {
                $this->appendDirectivePlayback($xml, $digitCondition, $option);
                $hangup = $digitCondition->appendChild($xml->createElement('action'));
                $hangup->setAttribute('application', 'hangup');
                $hangup->setAttribute('data', 'NORMAL_CLEARING');

                continue;
            }

            $action = $digitCondition->appendChild($xml->createElement('action'));
            if ($type === 'hangup') {
                $action->setAttribute('application', 'hangup');
                $action->setAttribute('data', 'NORMAL_CLEARING');
            } elseif ($type === 'queue') {
                $action->setAttribute('application', 'callcenter');
                $action->setAttribute('data', 'nr_'.$destination.'@default');
            } else {
                $action->setAttribute('application', 'bridge');
                // Browser extensions are registered through Kamailio
                // on the external profile. `user/<extension>` looks
                // up FreeSWITCH directory users instead, so an IVR
                // key press would wait and then fail to bridge.
                $action->setAttribute('data', sprintf(
                    'sofia/external/%s@%s:%d',
                    str_replace('|', '', $destination),
                    config('telephony.sip_server'),
                    (int) config('telephony.sip_port'),
                ));
            }
        }
    }
}
