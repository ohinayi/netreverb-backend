<?php

namespace App\Http\Controllers;

use App\Models\AiAssistantSession;
use App\Models\OrganizationIvr;
use App\Models\ServiceNumber;
use App\Services\Telephony\AiAssistantCallFlow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

class FreeSwitchDialplanRouterController extends Controller
{
    public function __invoke(Request $request, FreeSwitchDialplanController $dialplan)
    {
        $callerExtension = (string) ($request->input('Caller-Caller-ID-Number')
            ?: $request->input('variable_sip_from_user')
            ?: $request->input('Caller-Username'));
        $number = (string) ($request->input('destination_number') ?: $request->input('Caller-Destination-Number'));
        $contextName = (string) ($request->input('context') ?: $request->input('Caller-Context'));
        $baseTunnelUrl = (string) config('telephony.freeswitch.xml_curl_local_tunnel_url');

        // Each developer owns a distinct extension-number prefix (e.g. one
        // dev's local extensions all start with 900, another's with 100),
        // routed to that dev's own reverse-tunnel port. New local extensions
        // just need to fall under an existing prefix - no per-extension
        // config edit required.
        $prefixPorts = config('telephony.freeswitch.xml_curl_local_test_extension_prefix_ports', []);
        $port = null;
        foreach ($prefixPorts as $prefix => $prefixPort) {
            if ($callerExtension !== '' && str_starts_with($callerExtension, (string) $prefix)) {
                $port = $prefixPort;
                break;
            }
        }

        // Production already knows this request - always resolve it there,
        // even for a local-test caller. Local-only routing is a fallback for
        // things production has never heard of (e.g. a service number
        // created just for local testing), not a blanket redirect for every
        // call/leg a local-test extension is involved in.
        //
        // This has two cases. (1) The initial dial: a real, enabled
        // ServiceNumber production already knows about. Without this check,
        // a local dev extension calling a real production service number
        // silently got production's "unknown number" hairpin-bridge
        // fallback instead of the real IVR/assistant, since only local's own
        // (unrelated) database was ever consulted. (2) A mid-call
        // continuation - an IVR digit-press re-fetch (context
        // "ivr-options-<id>") or an AI assistant answer/confirm/DTMF
        // continuation. FreeSWITCH's re-fetch for these sends the pressed
        // digit as destination_number, not the original service number, so
        // checking ServiceNumber again here would (and did) always come back
        // false and misroute the continuation to the wrong backend, even
        // though the flow it belongs to is unambiguously production's - the
        // context id was minted by whichever backend generated the IVR/
        // assistant document in the first place, so its existence there is
        // exactly what tells the two apart.
        $prodOwnsThisRequest = $this->prodHasServiceNumber($number)
            || $this->prodOwnsContinuationContext($contextName);

        if ($port !== null && $baseTunnelUrl !== '' && ! $prodOwnsThisRequest) {
            $localTunnelUrl = (string) preg_replace('/:\d+\b/', ':'.$port, $baseTunnelUrl, 1);

            try {
                $response = Http::asForm()
                    ->timeout(10)
                    ->post($localTunnelUrl, $request->all());

                return response($response->body(), $response->status(), [
                    'Content-Type' => $response->header('Content-Type', 'text/xml; charset=UTF-8'),
                ]);
            } catch (Throwable $exception) {
                report($exception);
                // Fall through to production below instead of hard-failing -
                // a dead/unstarted local tunnel shouldn't break the call when
                // production might still be able to route it.
            }
        }

        return $dialplan($request);
    }

    private function prodHasServiceNumber(string $number): bool
    {
        return $number !== '' && ServiceNumber::query()
            ->where('enabled', true)
            ->whereHas('dialableNumber', fn ($query) => $query->where('number', $number))
            ->exists();
    }

    private function prodOwnsContinuationContext(string $contextName): bool
    {
        if (str_starts_with($contextName, 'ivr-options-')) {
            $publicId = substr($contextName, strlen('ivr-options-'));

            return OrganizationIvr::query()->where('public_id', $publicId)->exists();
        }

        foreach ([
            AiAssistantCallFlow::ANSWER_CONTEXT_PREFIX,
            AiAssistantCallFlow::CONFIRM_CONTEXT_PREFIX,
            AiAssistantCallFlow::DTMF_CONTEXT_PREFIX,
        ] as $prefix) {
            if (str_starts_with($contextName, $prefix)) {
                $publicId = substr($contextName, strlen($prefix));

                return AiAssistantSession::query()->where('public_id', $publicId)->exists();
            }
        }

        return false;
    }
}
