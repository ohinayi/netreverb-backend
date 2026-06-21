# NetReverb Local Development

## Start the application

When the Kamailio database is required, first keep the SSH tunnel open in its
own terminal:

```bash
ssh -N -L 3307:127.0.0.1:3306 deploy@sip.classyra.com.ng
```

Start all local Laravel processes with one command:

```bash
composer run dev
```

This starts:

- Laravel HTTP server on `http://localhost:8000`
- Queue worker for `telephony,default`
- Laravel scheduler worker
- Application log viewer
- Vite development server

Stopping `composer run dev` stops all five processes. Do not start duplicate
queue workers unless intentionally testing concurrency.

## Email delivery

Verification email is queued on `default`. The development command processes it
automatically. A successful API response only confirms that the notification
was accepted for processing; SMTP can still reject delivery afterward.

After changing `.env` mail settings, restart the development command or run:

```bash
php artisan optimize:clear
php artisan queue:restart
```

Failed mail jobs can be retried after fixing SMTP:

```bash
php artisan queue:retry all
```

The queue worker cannot overcome invalid SMTP credentials. A `535 Incorrect
authentication data` error must be fixed at the mailbox/provider.

## WebRTC bootstrap

Set these values in `.env`; `TURN_SECRET` must exactly match Coturn's
`static-auth-secret` value and must never be committed:

```dotenv
TURN_SECRET=replace-with-the-existing-coturn-shared-secret
TURN_HOST=sip.classyra.com.ng
TURN_PORT=3478
TURNS_PORT=5349
TURN_CREDENTIAL_TTL=600
KAMAILIO_WSS_URL=wss://sip.classyra.com.ng:7443
SIP_REGISTRATION_EXPIRES=300
```

Coturn must have shared-secret authentication enabled. After changing Laravel
environment values, run `php artisan optimize:clear` and restart the API and
queue processes. The authenticated frontend endpoint is
`GET /api/v1/webrtc/bootstrap`.

## Production

Do not use `composer run dev` in production. Run queue workers under Supervisor,
systemd, Laravel Horizon, or the hosting platform's process manager. Run the
scheduler continuously or invoke `php artisan schedule:run` once per minute.
