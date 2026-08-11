<?php

namespace App\Http\Controllers;

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
        $localExtensions = config('telephony.freeswitch.xml_curl_local_test_extensions', []);
        $localTunnelUrl = (string) config('telephony.freeswitch.xml_curl_local_tunnel_url');

        if ($callerExtension !== '' && in_array($callerExtension, $localExtensions, true)) {
            if ($localTunnelUrl === '') {
                return response('', 503, ['Content-Type' => 'text/xml; charset=UTF-8']);
            }

            try {
                $response = Http::asForm()
                    ->timeout(10)
                    ->post($localTunnelUrl, $request->all());

                return response($response->body(), $response->status(), [
                    'Content-Type' => $response->header('Content-Type', 'text/xml; charset=UTF-8'),
                ]);
            } catch (Throwable $exception) {
                report($exception);

                return response('', 503, ['Content-Type' => 'text/xml; charset=UTF-8']);
            }
        }

        return $dialplan($request);
    }
}
