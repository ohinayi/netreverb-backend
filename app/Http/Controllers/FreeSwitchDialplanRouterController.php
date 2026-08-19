<?php

namespace App\Http\Controllers;

use App\Models\ServiceNumber;
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
        $localExtensions = config('telephony.freeswitch.xml_curl_local_test_extensions', []);
        $localTunnelUrl = (string) config('telephony.freeswitch.xml_curl_local_tunnel_url');
        $portOverrides = config('telephony.freeswitch.xml_curl_local_test_extension_ports', []);

        $isLocalTestCaller = $callerExtension !== '' && in_array($callerExtension, $localExtensions, true);

        // Each developer's own extension can tunnel to their own port, so two
        // people can run `composer dev` at the same time without one of them
        // stealing the other's reverse tunnel.
        if ($isLocalTestCaller && isset($portOverrides[$callerExtension]) && $localTunnelUrl !== '') {
            $localTunnelUrl = (string) preg_replace('/:\d+\b/', ':'.$portOverrides[$callerExtension], $localTunnelUrl, 1);
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

        if ($isLocalTestCaller && $localTunnelUrl !== '' && ! $prodHasServiceNumber) {
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
