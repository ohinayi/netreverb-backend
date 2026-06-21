   # NetReverb Web Frontend Implementation Prompt

Use this document as the starting prompt for an implementation agent. Attach or
make the agent read the referenced files before it writes code.

---

You are implementing the NetReverb web client as a production Vue application.
Read these files completely before making architectural or API decisions:

1. `docs/FRONTEND_PRODUCT_SPEC.md` - product, platform, UX and security rules
2. `docs/openapi.yaml` - authoritative implemented HTTP API contract
3. `docs/NETREVERB_MASTER_BLUEPRINT.md` - system architecture and roadmap

## Required stack

- Vue 3 Composition API using `<script setup>`
- TypeScript strict mode
- Vite
- Vue Router
- Pinia only for client-owned state
- TanStack Query for Vue for API/server state
- Zod or Valibot for form/client validation
- API types/client generated from `docs/openapi.yaml`
- Vitest and Vue Test Utils
- Playwright for critical end-to-end workflows

Do not replace the stack, introduce Nuxt, or add a dependency without explaining
why a platform or standard API cannot solve the requirement.

## API contract rules

`docs/openapi.yaml` is authoritative for endpoints, methods, request bodies,
response bodies, validation shapes and authentication. Do not infer API fields
from Laravel migrations and do not invent endpoints.

The currently implemented API includes:

- Registration
- Login and current-token logout
- Current user/profile with assigned extensions
- Email verification and verification resend
- Organization list, create, show and update
- Extension list, create, show, update and delete
- SIP credential rotation
- Service-number list, create, show, update and delete

The following are product requirements but do not yet have backend endpoints:

- Password reset and profile update
- Member invitation, role and bulk-management APIs
- Browser SIP bootstrap/registration presence
- Call initiation, call events, CDRs and recordings
- Conversations, messages, receipts and reactions
- Image/file upload authorization and attachment scanning
- Voice-note upload and playback metadata
- Meetings and SFU join tokens
- AI assistants, knowledge sources and interactions
- Audit-log, webhook and API-token management

For these future modules, create no fake API client or mock production success.
Use disabled navigation, feature flags, clearly labelled placeholders, or local
design prototypes until the OpenAPI contract adds the real endpoints.

## Authentication workflow

1. Register with `POST /api/v1/auth/register` using the exact OpenAPI body.
2. Keep the returned initial bearer token in memory for development.
3. Show the verification-required screen while `email_verified=false`.
4. Resend through `POST /api/v1/email/verification-notification`.
5. The email opens `/auth/verify-email?verification_url=...`. Call the supplied
   signed backend URL with `Accept: application/json`, remove the sensitive
   query parameter with `history.replaceState`, then refresh `GET /api/v1/me`.
6. Verified users may access organization and telephony administration routes.
7. Revoke the current token with `DELETE /api/v1/auth/logout`.
8. Handle `401`, verification `403`, authorization `403`, `404`, `422`, `429`
   and `5xx` separately as defined in the product specification.

For the production first-party SPA, plan migration to Sanctum secure HTTP-only
cookie authentication after API/frontend domains and CORS are finalized. Never
store bearer tokens or SIP passwords in `localStorage`, IndexedDB, URLs,
analytics or error reports.

## Required first delivery

Implement in this order:

1. Project configuration, linting, formatting and test setup
2. Design tokens and accessible application shell
3. Typed API client and normalized error handling
4. Registration form using the exact OpenAPI fields
5. Login, logout and authenticated route guards
6. Email-verification state and resend countdown
7. Current-user/profile query
8. Organization selector and organization CRUD permitted by the contract
9. Extension list/create/show/update/delete
10. Assigned-user automatic SIP registration and credential rotation confirmation
11. Service-number list/create/show/update/delete with provisioning state
12. Unit, component and Playwright coverage for critical workflows

## SIP credential requirements

An extension-create response may contain `meta.sip_password`. A credential
rotation response contains `data.sip_password`. Display these only once in a
blocking acknowledgement dialog. Do not persist, log, cache, prefetch or send
them to analytics. Explain that rotation disconnects devices using the old
credential.

When the signed-in user starts the softphone, fetch
`GET /webrtc/bootstrap` for that user's assigned extension. If the user has
multiple active extensions, retry with `?extension_id={extension ULID}` after
selection. Use `wss` for SIP.js transport, `sip` for registration and
`iceServers` for WebRTC peer connection configuration. Pass the response
directly to the isolated SIP client service, retain it in memory only, and
clear it on logout, softphone shutdown, or authorization failure. Refresh TURN
configuration before `expires_at`; `sip.expires` is the SIP REGISTER refresh
interval. Never display the password or send bootstrap fields to analytics.

## UI state requirements

Every screen must implement loading, empty, success, validation, permission,
not-found, rate-limited and retryable-server-error states. Provisioning UI must
distinguish `pending`, `processing`, `active`, `failed` and `disabled`. Subscriber
existence does not mean a device is currently registered.

Use public ULIDs returned as `id` in URLs and API calls. Never expose Laravel's
internal numeric primary or foreign keys.

## Calls, messages and files

Design components according to `FRONTEND_PRODUCT_SPEC.md`, including audio/video
selection, accepted mid-call video upgrade, messaging, files and voice notes.
Do not connect those components to invented APIs. Live RTP/audio/video must
never pass through Laravel.

Files and voice notes will use private object storage with signed transfers,
validation and scan state. Do not store binary media in application state longer
than necessary. Do not claim end-to-end encryption until an audited E2EE protocol
and client key-management system exist.

## Quality and security gate

- WCAG 2.2 AA target and full keyboard operation
- No unsafe HTML rendering
- Strict Content Security Policy compatibility
- No executable CDN dependencies
- Minimal reviewed dependencies with committed lockfile
- No secrets in client logs, state persistence, analytics or screenshots
- Route-level code splitting
- Media tracks stopped on call end/logout
- API requests cancellable on navigation
- Generated types refreshed whenever `docs/openapi.yaml` changes
- Tests must prove tenant 403/404 handling and one-time-secret behaviour

Before coding each module, list the OpenAPI operations it uses. If a required
operation is absent, mark the module blocked by backend contract rather than
inventing a workaround.

---
