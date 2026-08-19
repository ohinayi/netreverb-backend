# NetReverb telephony and IVR runbook

This document is the handover for browser calls, FreeSWITCH XML-cURL, SSH
tunnels, service-number IVRs, and Piper text-to-speech.

## Architecture

The browser registers to Kamailio at `sip.classyra.com.ng`. Kamailio and
FreeSWITCH are on the VPS. Laravel owns IVR configuration and returns dynamic
FreeSWITCH dialplan XML.

During local development, Laravel runs on the laptop at `127.0.0.1:8000`.
The reverse SSH tunnel exposes it to FreeSWITCH on the VPS at
`127.0.0.1:8001`.

```
browser -> Kamailio -> FreeSWITCH -> XML-cURL router
                                         |- production Laravel
                                         `- tunnel -> local Laravel
```

The router selects local Laravel only when the caller extension belongs to the
explicit local-test list. All other calls remain on production. Therefore one
permanent FreeSWITCH XML-CURL URL can serve both environments.

## Important backend routes

* `/api/freeswitch/dialplan.xml` is the actual dialplan generator.
* `/api/freeswitch/dialplan-router.xml` is the permanent VPS-facing router.
* `/api/freeswitch/callcenter.xml` is the queue configuration endpoint.

Every XML-CURL request needs `?token=FREESWITCH_XML_CURL_TOKEN`.

## Local development

Start local services from the backend:

```bash
cd /home/abduljabbar/netreverb-backend
composer dev
```

`composer dev` must keep the reverse tunnel alive, including:

```
-R 127.0.0.1:8001:127.0.0.1:8000
```

Check the tunnel from the VPS:

```bash
ssh -t deploy@sip.classyra.com.ng "curl -i --max-time 10 http://127.0.0.1:8001/up"
```

If port 8001 is held by a stale tunnel, inspect it first, then terminate only
the listener on that exact port:

```bash
ssh -t deploy@sip.classyra.com.ng "sudo ss -ltnp | grep ':8001' || true"
ssh -t deploy@sip.classyra.com.ng "sudo fuser -k 8001/tcp"
```

## Permanent local + production router

Deploy this backend before changing FreeSWITCH. On the production Laravel
environment set:

```dotenv
FREESWITCH_XML_CURL_LOCAL_TEST_EXTENSIONS=100000,100001,100003,100005
FREESWITCH_XML_CURL_LOCAL_TUNNEL_URL=http://127.0.0.1:8001/api/freeswitch/dialplan.xml?token=YOUR_XML_CURL_TOKEN
```

Use only extensions that are genuinely local test accounts. Production callers
must not be included in this list.

After deployment, set FreeSWITCH XML-CURL **once** to this permanent URL:

```text
https://netreverb.classyra.com.ng/api/freeswitch/dialplan-router.xml?token=YOUR_XML_CURL_TOKEN
```

Then reload the XML-CURL module once:

```bash
sudo /usr/local/freeswitch/bin/fs_cli -x "reload mod_xml_curl"
sudo /usr/local/freeswitch/bin/fs_cli -x "reloadxml"
```

Do not use `switch-freeswitch-dialplan.sh` after this router is deployed. The
tunnel being up enables local callers; the tunnel being absent causes only
local-test callers to receive a temporary failure. Production calls continue.

## Piper IVR audio

Piper WAV files are generated when an IVR is created or edited. Uploaded audio
always wins and is never overwritten by generated audio.

Local configuration:

```dotenv
IVR_TTS_DRIVER=piper
PIPER_BINARY=/home/abduljabbar/.local/bin/piper
PIPER_MODEL=/home/abduljabbar/netreverb-backend/storage/app/piper-voices/en_US-lessac-medium.onnx
PIPER_LENGTH_SCALE=1.05
PIPER_OUTPUT_DISK=public
FREESWITCH_IVR_AUDIO_BASE_URL=http_cache://127.0.0.1:8001
```

Install local Piper without sudo:

```bash
python3 -m pip install --user --break-system-packages piper-tts
python3 -m piper.download_voices --data-dir /home/abduljabbar/netreverb-backend/storage/app/piper-voices en_US-lessac-medium
```

Production configuration uses the VPS installation:

```dotenv
IVR_TTS_DRIVER=piper
PIPER_BINARY=/opt/piper/venv/bin/piper
PIPER_MODEL=/opt/piper/voices/en_US-lessac-medium.onnx
PIPER_OUTPUT_DISK=public
```

Available voice IDs are `en_US-lessac-medium`, `en_US-amy-medium`,
`en_US-ryan-medium`, and `en_GB-alan-medium`. The IVR page stores the selected
voice and displays an HTML audio player for its generated prompt. After changing
the voice, save the IVR to regenerate the WAV. Each selected model must exist
on the environment doing the generation.

### FreeSWITCH playback requirement for local prompts

Local Laravel files are reached through the tunnel as
`http_cache://127.0.0.1:8001/storage/...`. This requires FreeSWITCH
`mod_http_cache`. Check it with:

```bash
sudo /usr/local/freeswitch/bin/fs_cli -x "module_exists mod_http_cache"
```

If it reports `false`, build it from the existing FreeSWITCH source before
testing local Piper audio:

```bash
cd /usr/local/src/freeswitch-1.10.12
sudo sed -i 's|^#applications/mod_http_cache$|applications/mod_http_cache|' modules.conf
sudo ./config.status --recheck
sudo make mod_http_cache-install
sudo sed -i 's|<!-- <load module="mod_http_cache"/> -->|<load module="mod_http_cache"/>|' /usr/local/freeswitch/etc/freeswitch/autoload_configs/modules.conf.xml
sudo /usr/local/freeswitch/bin/fs_cli -x "load mod_http_cache"
sudo /usr/local/freeswitch/bin/fs_cli -x "module_exists mod_http_cache"
```

The final command must return `true`. This is a one-time VPS setup. Do not
reload/restart FreeSWITCH during an active call.

To regenerate all text IVR prompts, preserving uploaded audio:

```bash
php artisan tinker --execute='$ivrs = App\Models\OrganizationIvr::with(["organization", "options"])->get(); $ivrs->each(function ($ivr) { $path = "ivr-welcome/{$ivr->organization->public_id}/{$ivr->public_id}.wav"; if (blank($ivr->welcome_text) || ($ivr->welcome_audio_path && $ivr->welcome_audio_path !== $path)) return; $menu = $ivr->options->where("enabled", true)->map(fn ($option) => "Press {$option->digit} for {$option->label}.")->implode(" "); if ($generated = app(App\Services\Telephony\PiperTtsService::class)->generate(trim($ivr->welcome_text.". ".$menu), $path)) $ivr->update(["welcome_audio_path" => $generated]); });'
```

When Piper audio exists, the dialplan must show `application="playback"`.
`application="speak"` means audio generation failed or the IVR was not
resaved/regenerated.

## Service numbers without an IVR

A service number can bridge straight to `target` with no IVR attached.
`FreeSwitchDialplanController` used to bridge that path with
`bridge user/<target>`, which only resolves FreeSWITCH's own directory users.
Browser/softphone extensions register externally through Kamailio, so that
bridge silently failed while dialing the extension number directly worked
(it goes through the `sofia/external/...` branch instead). Fixed: the
no-IVR branch now bridges through `sofia/external/<target>@<sip_server>:<port>`,
matching every other bridge path in this file. If a service number still
doesn't route after this fix, check `configuration.ivr_public_id` on the
`service_numbers` row and confirm `target` holds a bare extension number.

## Voice preview, per-IVR speed, and auto-regeneration

* Editing `welcome_text` (or menu options) already regenerates the Piper WAV
  automatically on save — this was already true before this note was added,
  via `OrganizationIvrController::synthesizePrompt()`. Uploaded audio always
  wins and is never auto-regenerated.
* `organization_ivrs.tts_speed` (decimal, default `1.00`) now stores a
  per-IVR speed dial in the `0.5`–`2.0` range. It maps directly to Piper's
  `--length_scale`: **lower is faster, higher is slower** (this is inverted
  from what "speed" intuitively suggests — do not flip the sign when
  touching `PiperTtsService::generate()`).
* `POST /api/v1/organizations/{organization}/ivrs/preview` (see
  `OrganizationIvrController::preview()`) generates a throwaway WAV under
  `ivr-preview/<org>/<uuid>.wav` from arbitrary `welcome_text` + `tts_voice`
  + `tts_speed` without touching any saved IVR row. The frontend IVR form
  (`OrganizationIvrsView.vue`) calls this to let an admin audition a voice
  and speed before saving. Preview files are not currently garbage
  collected — if `storage/app/public/ivr-preview` grows large, add a
  scheduled cleanup.

## IVR routing behavior

1. An organization creates an active service number.
2. The organization creates an IVR and attaches that service number.
3. FreeSWITCH answers, waits 1.5 seconds, plays uploaded/Piper audio, reads one
   DTMF digit, and transfers into that IVR's private option context.
4. Extension destinations bridge through
   `sofia/external/<extension>@sip.classyra.com.ng:5060`.
5. Queue destinations use `callcenter nr_<queue>@default`.

The private context is required: routing a pressed digit through the broad
`public` context can match unrelated dialplan extensions. Do not change IVR
options back to `bridge user/<extension>`; browser clients are reached through
the external SIP profile.

## Useful checks

Generate/inspect local dialplan XML in one line:

```bash
curl -sS -X POST 'http://127.0.0.1:8000/api/freeswitch/dialplan.xml?token=YOUR_XML_CURL_TOKEN' --data 'section=dialplan&destination_number=YOUR_SERVICE_NUMBER&context=public'
```

Inspect the same local endpoint through the VPS tunnel:

```bash
ssh -t deploy@sip.classyra.com.ng "curl -sS -X POST 'http://127.0.0.1:8001/api/freeswitch/dialplan.xml?token=YOUR_XML_CURL_TOKEN' --data 'section=dialplan&destination_number=YOUR_SERVICE_NUMBER&context=public'"
```

Check FreeSWITCH health and live calls:

```bash
sudo /usr/local/freeswitch/bin/fs_cli -x status
sudo /usr/local/freeswitch/bin/fs_cli -x "show channels as json"
```

Check whether a browser extension is registered on the external profile:

```bash
sudo /usr/local/freeswitch/bin/fs_cli -x "sofia status profile external reg"
```

Tail IVR activity while placing a test call:

```bash
sudo tail -F /usr/local/freeswitch/log/freeswitch.log | grep -Ei 'ivr|xml_curl|dialplan|read\(|transfer\(|bridge\(|originate|hangup|error'
```

## Known failure patterns

* `remote port forwarding failed for listen port 8001`: stale SSH session owns
  VPS port 8001; inspect and kill only that port's listener.
* `Unable to connect ... 127.0.0.1:8021`: the local SSH tunnel is not running.
* HTTP 404 from production `dialplan.xml`: the backend version deployed on the
  VPS does not contain the new XML route/controller yet.
* Call speaks then immediately ends: check `read` syntax and the private IVR
  context. The correct read action uses a millisecond timeout, not `5.wav`.
* Pressing a digit ends: verify generated XML has `transfer ${ivr_digit} XML
  ivr-options-...` and an exact option extension with an external-profile
  bridge target. If the XML is already correct, the bridge target is very
  likely simply not registered at that moment — check with:
  ```bash
  sudo /usr/local/freeswitch/bin/fs_cli -x "sofia status profile external reg" | grep <extension>
  ```
  No matching line means the softphone/browser client for that extension is
  not logged in, so the bridge has nowhere to send the call and the leg ends
  immediately after the digit is read. This is not a dialplan bug; log the
  destination extension in before testing.
* No UUID for a browser call: first confirm the event-socket tunnel on local
  port 8021 and that FreeSWITCH has a live channel.

## Deployment checklist

1. Commit/deploy backend code and run migrations.
2. Set production Piper and router environment values.
3. Restart/reload the production Laravel workers as appropriate.
4. Set FreeSWITCH XML-CURL to `dialplan-router.xml` once, then reload it.
5. Keep local `composer dev` and the reverse tunnel running only when testing
   local extensions.
6. Test one production extension and one local-test extension separately.
