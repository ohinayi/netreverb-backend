# NetReverb Frontend Product Specification

## 1. Product Direction

The frontend is a responsive communications workspace, not only an admin
dashboard. It must support an individual user, an organization member, an
administrator, and eventually an embedded customer-service agent without
mixing their permissions or telephony credentials.

Use React with TypeScript. Treat the Laravel API as the source of truth and
keep SIP/WebRTC session state in a dedicated client service. Do not store SIP
passwords, bearer tokens, or call recordings in browser local storage.

## 2. Information Architecture

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

## 3. Core Navigation

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

## 4. Design System

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

## 5. Registration and Verification

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

- Show the destination email in masked form
- Explain that verification activates the SIP extension
- Resend through `POST /api/v1/email/verification-notification`
- Apply a visible resend countdown
- Allow sign out and changing account details later
- Handle `success`, `expired`, `invalid` and `already verified` states

When the email link succeeds, refresh `GET /api/v1/me`. The new extension may
be `pending` while the telephony queue synchronizes Kamailio.

## 6. Authentication State

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

## 7. Home and Telephony Status

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

## 8. Extensions

### List

Columns: display name, number, type, assigned user, operational status,
provisioning status and updated time. Owners/admins see Create, Edit, Disable,
Delete and Rotate Credential actions according to policy.

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

## 9. Service Numbers

Service numbers define desired routing state. Show number, type, target,
enabled state and `provisioning_status`. A successful API update does not mean
Kamailio/FreeSWITCH has reloaded until provisioning becomes active.

Initial examples:

- Echo test: public number `459666`, current FreeSWITCH target `9666`
- Conference: public number `45000`, target based on the deployed dialplan

Changing a public service number requires a separate staged-deployment workflow
before production. The frontend must not present direct editing as immediately
live until that backend workflow exists.

## 10. Calls, Messages and Meetings

### Calls

The dialler supports numeric extension entry, contact selection, mute, keypad,
hold, transfer placeholder, device selection and network-quality feedback.
Separate API authentication from SIP registration. A browser softphone receives
bootstrap details from a future endpoint and keeps media state outside global
React rendering loops.

### Messages

Conversation list, durable message history, unread state, delivery/read state,
attachments and offline cursor synchronization. SIP MESSAGE is not the primary
chat transport.

### Meetings

Meeting list, create/schedule, invitation, waiting room, participant controls,
screen sharing and meeting chat. Video UI must target the selected SFU rather
than assuming FreeSWITCH provides scalable browser video.

## 11. Organization Administration

Owners/admins manage members, roles, extension-provisioning mode, service
numbers, integrations, retention and security. Provisioning modes:

- Automatic
- Approval required
- Invite only
- Manual

Bulk member/import screens must support validation previews and partial-failure
reports. Organization deletion is intentionally unavailable until backend
offboarding can deactivate SIP identities and enforce retention safely.

## 12. AI Assistant Experience

Assistant screens configure identity, assigned number, voice, prompt,
knowledge bases, business hours and human handoff. Knowledge source states are
uploading, indexing, ready, failed and deleting.

Interaction history shows question, answer, citations, latency, outcome and
feedback. Never expose another tenant's source identifiers. Voice screens show
STT/RAG/TTS health as one assistant session, while keeping those services
separate in diagnostics.

## 13. Frontend Engineering Standards

- TypeScript strict mode
- Generated API types from `docs/openapi.yaml`
- Server state through TanStack Query or equivalent
- Form state through a schema-backed form library
- Central API client with timeout, abort support and normalized errors
- Route-level code splitting
- Error boundaries around telephony, meetings and AI modules
- Accessible keyboard navigation and WCAG 2.2 AA targets
- Unit tests for state reducers and formatters
- Component tests for forms, permissions and one-time secrets
- End-to-end tests for registration, verification, extension creation and calls
- No secrets or sensitive transcripts in analytics/error-report payloads

## 14. Initial Frontend Delivery Order

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

The frontend must be implemented against the OpenAPI contract and backend test
environment, not inferred from database tables.
