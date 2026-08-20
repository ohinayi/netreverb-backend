<?php

namespace App\Http\Controllers;

use App\Models\ServiceNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FreeSwitchDialplanRouterController extends Controller
{
    public function __invoke(Request $request, FreeSwitchDialplanController $dialplan)
    {
        $callerExtension = (string) ($request->input('Caller-Caller-ID-Number')
            ?: $request->input('variable_sip_from_user')
            ?: $request->input('Caller-Username'));
        $number = (string) ($request->input('destination_number') ?: $request->input('Caller-Destination-Number'));
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

        // Production already knows this destination (a real, enabled service
        // number) - always resolve it there, even for a local-test caller.
        // Local-only routing is a fallback for numbers production has never
        // heard of (e.g. a service number created just for local testing),
        // not a blanket redirect for every call a local-test extension
        // places. Without this check, a local dev extension calling a real
        // production service number silently got production's "unknown
        // number" hairpin-bridge fallback instead of the real IVR/assistant,
        // since only local's own (unrelated) database was ever consulted.
        $prodHasServiceNumber = $number !== '' && ServiceNumber::query()
            ->where('enabled', true)
            ->whereHas('dialableNumber', fn ($query) => $query->where('number', $number))
            ->exists();

        Log::error('dialplan_router_decision', [
            'context' => (string) $request->input('context', $request->input('Caller-Context')),
            'callerExtension' => $callerExtension,
            'destination_number' => $number,
            'resolvedPort' => $port,
            'prodHasServiceNumber' => $prodHasServiceNumber,
            'willRouteLocal' => $port !== null && $baseTunnelUrl !== '' && ! $prodHasServiceNumber,
        ]);

        if ($port !== null && $baseTunnelUrl !== '' && ! $prodHasServiceNumber) {
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
}
