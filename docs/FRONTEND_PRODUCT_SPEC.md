# NetReverb Frontend Product Specification

## 1. Product Direction

The frontend is a responsive communications workspace, not only an admin
dashboard. It must support an individual user, an organization member, an
administrator, and eventually an embedded customer-service agent without
mixing their permissions or telephony credentials.

Use Vue 3 with TypeScript for the web application. Treat the Laravel API as the
source of truth and keep SIP/WebRTC session state in a dedicated client service.
Do not store SIP passwords, bearer tokens, or call recordings in browser local
storage.

The web application is the first client and the primary administration and
collaboration surface. Desktop and mobile are later clients of the same API;
they add operating-system capabilities rather than introducing separate
business rules.

### Chosen web stack

- Vue 3 Composition API
- TypeScript in strict mode
- Vite
- Vue Router
- Pinia for small client-owned state
- TanStack Query for API/server state
- Zod or Valibot for client-side form/schema validation
- Generated TypeScript API client and types from `docs/openapi.yaml`
- Vitest and Vue Test Utils
- Playwright for browser end-to-end tests

Do not introduce Nuxt initially. NetReverb is primarily an authenticated
application and does not need server-side rendering for its workspace. Public
marketing pages may later use a separate static site or Nuxt application if
search-engine requirements justify it.

### Why Vue 3

Vue gives the project a smaller set of standard architectural choices and a
stable official ecosystem. Framework choice does not provide security by
itself: dependency minimization, secure authentication, CSP, authorization,
testing, controlled upgrades and vulnerability monitoring remain mandatory.
SIP and WebRTC libraries must be isolated behind framework-independent service
interfaces so a UI framework upgrade cannot alter active call logic.

## 2. Platform Strategy

### Web first

The browser should contain almost every management and collaboration feature.
It may provide foreground WebRTC calls and meetings while open, but it must not
promise reliable incoming calls after the browser or operating system suspends
the page.

### Progressive Web App

After the responsive website is stable, make it installable as a PWA. The PWA
adds home-screen installation, application-like navigation, cached static
assets and supported web notifications. It does not turn the browser into a
fully native background VoIP client.

### Desktop

Desktop clients target Linux, macOS and Windows. Prototype both options before
committing:

- **Tauri:** smaller application and narrower native capability permissions,
  but relies on operating-system webviews whose WebRTC behaviour can vary.
- **Electron:** larger runtime and more frequent Chromium updates, but gives
  more consistent WebRTC/media behaviour across operating systems.

The decision gate is a measured prototype covering SIP over WSS, microphone and
speaker switching, screen/system-audio sharing, incoming-call notifications,
sleep/wake, headset controls, auto-update and signing on all three platforms.
Do not select a wrapper based only on installer size.

### Mobile

The production mobile client must support native calling integrations. Evaluate
Flutter first, with native platform modules where required. A WebView/Capacitor
wrapper may be used for an internal prototype, but is not the final VoIP client.

Required native integrations include:

- iOS CallKit and the approved push/wake mechanism
- Android ConnectionService and foreground call services
- APNs and FCM notifications
- Keychain/Keystore credential storage
- Background audio and lifecycle recovery
- Bluetooth/headset/audio-route control
- Camera, microphone and media permissions
- Biometric application lock

### Shared client architecture

All clients share the Laravel/OpenAPI contract, permission model, terminology,
design tokens and product behaviour. They do not share secrets or database
access. Web and desktop may share Vue components after the desktop prototype;
mobile shares generated API models and product rules but uses mobile-native UI.

Recommended repository boundaries:

```text
netreverb-backend       Laravel API and telephony control plane
netreverb-clients       Web/desktop workspace and shared TypeScript packages
  apps/web
  apps/desktop
  packages/api-client
  packages/design-tokens
netreverb-mobile        Flutter/native mobile client
```

## 3. Platform Capability Matrix

| Capability | Web/PWA | Desktop | Mobile |
| --- | --- | --- | --- |
| Registration, verification and login | Full | Full | Full |
| Organization and member administration | Primary | Full | Basic/approval |
| Extension and service-number management | Full | Full | Basic |
| Foreground voice/video calls | Full while open | Full | Full |
| Reliable background incoming calls | Limited | Full | Full |
| Operating-system call UI | No | Notifications/overlay | CallKit/ConnectionService |
| Chat, files and presence | Full | Full | Full |
| Meetings and screen sharing | Full foreground | Full plus system audio | Join/present as supported |
| Call history and recordings | Full | Full | Full |
| AI assistant and knowledge configuration | Primary | Full | Basic monitoring |
| Secure native credential storage | Browser-limited | Keychain/credential vault | Keychain/Keystore |
| System tray, auto-start and global shortcuts | No | Full | Not applicable |
| Native contacts and share sheet | Limited | Limited | Full with permission |
| Bluetooth/headset routing | Browser-dependent | Enhanced | Native |
| Offline encrypted data | Minimal cache | Supported | Supported |
| Bulk import and large reports | Primary | Full | Not recommended |

Web limitations must be presented honestly in product copy. A browser tab being
closed, suspended, offline or denied notification/microphone permission is not
equivalent to a registered native phone client.

## 4. Information Architecture

### Public pages

- Landing page
- Features and use cases
- Pricing placeholder
- Developer/API overview
- Sign in
- Registration
- Forgot/reset password
- Email verification status
- Privacy, terms, acceptable-use and recording-consent pages

### Authenticated workspace

- Home/overview
- Calls and dialler
- Contacts
- Messages
- Meetings
- Extensions
- Service numbers
- Recordings
- AI assistants and knowledge bases
- Organization members and roles
- Integrations and API tokens
- Organization settings
- User profile, security and active sessions

Only implemented modules should be active in navigation. Future modules may be
shown as clearly labelled previews, but must never lead to fake operational
screens.

## 5. Core Navigation

Desktop uses a collapsible left navigation, a top context bar, and a primary
content area. Mobile uses a bottom navigation for Home, Calls, Messages,
Meetings and More. The current organization selector appears in the context
bar and must never silently change while a destructive form is open.

Persistent global actions:

- Open dialler
- Start meeting
- Search
- Notifications
- Connection/registration status
- User menu

## 6. Design System

Use a calm professional visual language suitable for hospitals, hotels,
schools and support teams.

- Primary colour: deep indigo/blue for navigation and trusted actions
- Success: green only for verified, connected and completed states
- Warning: amber for pending provisioning, weak connection and expiring access
- Danger: red for failed, disabled, destructive and security-sensitive states
- Neutral surfaces: white and cool gray with clear borders
- Typography: readable sans serif, minimum 16px body text
- Radius: moderate, consistent across fields, cards and dialogs
- Motion: short and optional; respect `prefers-reduced-motion`

Every semantic colour requires an icon and text label. Never communicate call,
verification or provisioning state by colour alone.

Reusable components:

- Application shell and organization switcher
- Data table with server pagination, filters and empty/error/loading states
- Status badge
- Confirmation dialog
- One-time-secret dialog
- Phone/SIP number display with copy action
- Connection-quality indicator
- Permission-denied panel
- Inline validation summary
- Toast for non-critical completion; persistent banner for security failures

## 7. Registration and Verification

### Registration form

Fields match `POST /api/v1/auth/register`:

- Full/display name
- Email
- Password and confirmation
- Country of residence (ISO 3166-1 alpha-2 value)
- Timezone, detected but editable
- Language/locale
- Terms and privacy acceptance
- Device name generated from browser/OS where possible

Password guidance must reflect the API rule: at least 12 characters with upper
and lower case letters, a number and a symbol. Display server validation errors
beside their exact fields.

On success, keep the bearer token in memory for the current client session and
show the Verify Email screen. The user has a personal workspace but receives no
SIP number until verification succeeds.

### Verify Email screen

- Email links open `/auth/verify-email?verification_url=...` on the frontend.
- Read `verification_url`, call that signed backend URL with
  `Accept: application/json`, then remove it from browser history immediately.
- Show the destination email in masked form
- Explain that verification activates the SIP extension
- Resend through `POST /api/v1/email/verification-notification`
- Apply a visible resend countdown
- Allow sign out and changing account details later
- Handle `success`, `expired`, `invalid` and `already verified` states

When the signed backend call succeeds, refresh `GET /api/v1/me`. The new extension may
be `pending` while the telephony queue synchronizes Kamailio.

## 8. Authentication State

The frontend state machine is:

```text
anonymous
  -> authenticated_unverified
  -> authenticated_verified
  -> workspace_ready
```

Handle HTTP responses consistently:

- `401`: clear client authentication state and show sign in
- `403` with verification message: show Verify Email
- Other `403`: show permission denied without hiding the current organization
- `404`: show not found; do not reveal whether another tenant owns the resource
- `422`: map `errors` to fields
- `429`: show retry guidance and preserve entered form data
- `500/503`: show recoverable system error with correlation ID when provided

For the initial bearer-token client, keep the token in memory. A production web
SPA should move to Sanctum's secure, HTTP-only cookie flow when frontend and API
domain/CORS design is finalized. Never place bearer or SIP credentials in URLs.

## 9. Home and Telephony Status

Home shows:

- User verification state
- Assigned extension and SIP URI
- Provisioning status
- Softphone registration state
- Recent calls
- Upcoming meetings
- Organization notices

Extension status presentation:

| API state | UI behaviour |
| --- | --- |
| `pending` | Show “Setting up your number” and poll with backoff |
| `processing` | Show active progress without allowing credential rotation |
| `active` | Enable calling and device setup |
| `failed` | Show retry/help path; never expose raw infrastructure error |
| `disabled` | Disable calling and explain who can reactivate it |

Do not claim a device is online based on subscriber existence. Registration
presence will come from a later Kamailio presence/registration endpoint.

## 10. Extensions

### List

Columns: display name, number, type, assigned user, operational status,
provisioning status and updated time. Owners/admins see Create, Edit, Disable,
Delete and Rotate Credential actions according to policy.

Owners/admins receive all organization extensions. Ordinary members receive
only extensions assigned to their user account and must not be offered another
member's extension URL or actions.

### Create

Fields: number, display name, type and optional active organization member.
The number collision error must explain that the number may already belong to
an extension or service route.

After creation, display the returned `meta.sip_password` once in a blocking
dialog with extension, realm, SIP URI and password. Require acknowledgement
before closing. Do not cache the password, include it in analytics, or render it
again after navigation.

### Credential rotation

Explain that registered devices using the old credential will stop working.
After confirmation, show the new credential once and force the affected local
softphone to re-register.

### Automatic registration

When an assigned user starts calling, request
`GET /webrtc/bootstrap`, optionally passing `extension_id` when more than one
active extension is assigned. Feed `wss`, `sip` and `iceServers` directly into
the isolated SIP/WebRTC client service. Refresh TURN configuration before
`expires_at`. The web client keeps the bootstrap response in memory only and
clears it on logout or softphone shutdown; native clients use the
operating-system credential vault. Do not render the password, cache the
response, prefetch it, or send any field to analytics.

## 11. Service Numbers

Service numbers define desired routing state. Show number, type, target,
enabled state and `provisioning_status`. A successful API update does not mean
Kamailio/FreeSWITCH has reloaded until provisioning becomes active.

Initial examples:

- Echo test: public number `459666`, current FreeSWITCH target `9666`
- Conference: public number `45000`, target based on the deployed dialplan

Changing a public service number requires a separate staged-deployment workflow
before production. The frontend must not present direct editing as immediately
live until that backend workflow exists.

## 12. Calls, Messages and Meetings

### Calls

Users choose **Audio call** or **Video call** from contacts, conversations and
the dialler. The call UI supports numeric extension entry, contact selection,
mute, camera on/off, camera switching, keypad, hold, transfer placeholder,
speaker/microphone/camera selection, picture-in-picture where supported and
network-quality feedback.

An audio call may request a mid-call video upgrade. The receiving user must
accept before the camera starts, and both clients must handle downgrade back to
audio without ending the call. Permission denial, missing camera, unsupported
codec and network degradation need explicit recoverable states.

Separate API authentication from SIP registration. A browser softphone receives
bootstrap details from a future endpoint and keeps signalling/media state
outside global Vue reactivity and rendering loops. Laravel stores call history
and media mode but never receives live audio/video packets.

One-to-one video may use the SIP/WebRTC/RTPengine path after codec testing.
Group video, screen sharing and large meetings use the selected SFU rather than
peer-to-peer mesh or FreeSWITCH audio conferencing.

### Messages

Conversation list, durable message history, unread state, delivery/read state,
replies, edits, soft deletion, reactions, attachments, voice notes and offline
cursor synchronization. SIP MESSAGE is not the primary chat transport.

Supported message types:

- Text and emoji
- Reply/quote
- Image with safe preview
- Document/file attachment
- Voice note with record, cancel, preview, send and playback controls
- System messages for calls, meetings and membership changes

Files and voice notes upload directly to private object storage using signed
authorization from Laravel. Show upload progress, cancellation, retry and scan
state. Do not display or download an attachment until the backend marks it safe.
Voice notes show duration, playback progress, speed and an accessible text
label; later transcription must be opt-in and visibly identified.

Message states are sending, sent, delivered, read, failed, edited and deleted.
Use client-generated idempotency keys so retrying after reconnect does not
duplicate a message.

Do not describe the product as end-to-end encrypted unless an audited E2EE
protocol and multi-device key system have actually been implemented. TLS and
encrypted object/database storage must instead be described accurately as
encryption in transit and at rest.

### Meetings

Meeting list, create/schedule, invitation, waiting room, participant controls,
screen sharing and meeting chat. Video UI must target the selected SFU rather
than assuming FreeSWITCH provides scalable browser video.

## 13. Web Feature Scope

### Available on web

- Registration, verification, login, logout and account recovery
- Personal workspace and multi-organization switching
- User profile, preferences, sessions and security settings
- Members, invitations, roles and bulk provisioning
- Extensions, assignments, devices and credential rotation
- Configurable service numbers and provisioning status
- Foreground browser softphone and dialler
- One-to-one audio/video selection and accepted mid-call video upgrade
- Contacts, favourites and searchable directory
- Call history, call details, recordings and exports
- Direct/group messaging, replies, reactions, files, images, voice notes and
  delivery/read state
- Meeting creation, scheduling, joining, chat and screen sharing
- Queue, IVR, ring-group and business-hour configuration
- AI assistant, knowledge source, prompt and handoff configuration
- AI interaction history, citations, feedback and usage visibility
- Webhooks, API credentials, integration status and developer documentation
- Audit history, retention settings and compliance controls
- Subscription and billing management when enabled

### Deliberately limited on web

- Incoming calls are reliable only while the browser/PWA is active and allowed
  to run by the operating system.
- Browser storage must not hold recoverable SIP secrets long-term.
- Headset buttons, Bluetooth routing and system-level audio control vary by
  browser and operating system.
- Screen/system-audio sharing varies by browser and always requires explicit
  user permission.
- No system dialler/CallKit/ConnectionService integration.
- Offline access is limited to non-sensitive cached UI and queued drafts.

## 14. Desktop Feature Scope

Desktop includes all web workspace features plus:

- Persistent registration and reliable incoming-call handling
- Persistent one-to-one audio/video calls and mid-call media upgrades
- System tray and background operation
- Global answer, hang-up, mute and push-to-talk shortcuts
- Auto-start policy controlled by the user/organization
- Native notifications and incoming-call overlay
- Secure OS credential vault
- Enhanced headset and audio-device management
- Screen and supported system-audio sharing
- Multiple windows for call controls, chat and monitoring
- Signed automatic updates with controlled rollout and rollback
- Diagnostic export with secrets removed
- Optional local encrypted cache for recent conversations and settings

The desktop application must disable Node/native access from untrusted rendered
content, restrict navigation, validate every native command, isolate the web
view, and sign releases for each operating system.

## 15. Mobile Feature Scope

Mobile prioritizes communication rather than heavy administration:

- Registration, verification, login and biometric lock
- Reliable push-triggered incoming calls
- Native incoming/outgoing call screen
- Background voice call continuity
- Direct and group chat, attachments and voice notes
- Audio and video calling with camera/audio-route switching
- Contact directory and optional native-contact matching with consent
- Call history, voicemail and recording playback
- Meeting join/create and camera switching
- Push notifications for calls, messages, meetings and mentions
- Bluetooth, speaker, earpiece and microphone routing
- Secure Keychain/Keystore tokens and device credentials
- Network handoff and reconnection handling
- Basic member approval and extension status for administrators
- AI assistant monitoring and human handoff notifications

Bulk imports, complex routing editors, knowledge-base management and detailed
reports remain web/desktop-first experiences.

## 16. Organization Administration

Owners/admins manage members, roles, extension-provisioning mode, service
numbers, integrations, retention and security. Provisioning modes:

- Automatic
- Approval required
- Invite only
- Manual

Bulk member/import screens must support validation previews and partial-failure
reports. Organization deletion is intentionally unavailable until backend
offboarding can deactivate SIP identities and enforce retention safely.

## 17. AI Assistant Experience

Assistant screens configure identity, assigned number, voice, prompt,
knowledge bases, business hours and human handoff. Knowledge source states are
uploading, indexing, ready, failed and deleting.

Interaction history shows question, answer, citations, latency, outcome and
feedback. Never expose another tenant's source identifiers. Voice screens show
STT/RAG/TTS health as one assistant session, while keeping those services
separate in diagnostics.

## 18. Frontend Engineering Standards

- TypeScript strict mode
- Generated API types from `docs/openapi.yaml`
- Vue 3 Composition API and `<script setup>` convention
- Vue Router with route-level authorization metadata
- Pinia only for client-owned state such as call UI and preferences
- Server state through TanStack Query for Vue
- Form state through a Zod/Valibot-backed form library
- Central API client with timeout, abort support and normalized errors
- Route-level code splitting
- Vue error boundaries around telephony, meetings and AI modules
- Accessible keyboard navigation and WCAG 2.2 AA targets
- Unit tests for state reducers and formatters
- Component tests for forms, permissions and one-time secrets
- End-to-end tests for registration, verification, extension creation and calls
- No secrets or sensitive transcripts in analytics/error-report payloads

### Dependency and supply-chain controls

- Commit lockfiles and use reproducible CI installs
- Prefer framework/standards APIs over convenience packages
- Require an engineering review before adding any runtime dependency
- Run npm vulnerability audit and license checks in CI
- Use automated dependency-update pull requests with tests, never automatic
  production deployment
- Pin critical telephony, cryptography, update and desktop-native dependencies
- Generate and retain a software bill of materials for releases
- Apply Content Security Policy and prohibit unsafe inline/eval execution
- Prohibit Vue `v-html` unless content is sanitized by an approved boundary
- Bundle production assets locally rather than loading executable CDN scripts
- Sign desktop/mobile releases and verify updates before installation

### Authentication and local data

- Use Sanctum HTTP-only secure cookies for the final first-party web SPA
- Store native tokens in OS Keychain/Keystore/credential vault
- Never store SIP passwords in `localStorage`, IndexedDB or application logs
- Never put bearer tokens, SIP credentials or meeting secrets in URLs
- Clear media tracks, memory-held credentials and sensitive caches on logout
- Require step-up authentication for credential rotation and destructive actions

## 19. Delivery Roadmap

### Phase 1: Web foundation

1. Design tokens, application shell and API client
2. Registration, login, verification and logout
3. User profile and organization selector
4. Home provisioning status
5. Extension list/create/update/rotation
6. Service-number list/create/update
7. Browser SIP bootstrap and calling
8. Call history and recordings
9. Messages
10. Meetings
11. AI assistants and knowledge bases

### Phase 2: Web communication hardening

1. PWA installation and offline static shell
2. Notification permissions and supported push notifications
3. Media-device setup and browser compatibility checks
4. Call reconnection, quality indicators and failure recovery
5. Accessibility and keyboard calling controls
6. Cross-browser end-to-end tests

### Phase 3: Desktop prototype and decision

1. Build the same calling prototype in Tauri and Electron
2. Test Linux, macOS and Windows media behaviour
3. Threat-model the native bridge and updater
4. Measure installer/memory/CPU, call quality and sleep/wake recovery
5. Select one runtime and document the architecture decision
6. Deliver signing, updates, crash recovery and secure credential storage

### Phase 4: Mobile

1. Flutter/native proof of concept for iOS and Android
2. Push-to-call and native call-screen integration
3. Background audio and network handoff
4. Chat, meetings, notifications and secure storage
5. Store signing, privacy declarations and staged release pipelines

### Phase 5: Shared maturity

1. Shared design-token distribution
2. Generated API clients for TypeScript and Dart
3. Cross-client presence and device/session management
4. Feature flags and minimum-supported-version enforcement
5. Coordinated security response and forced credential/session revocation

The frontend must be implemented against the OpenAPI contract and backend test
environment, not inferred from database tables.
