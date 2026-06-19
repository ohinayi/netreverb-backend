# NetReverb Hybrid Master Blueprint

## 1. Purpose

NetReverb is a multi-tenant communications platform for businesses, call
centres, hospitals, hotels, schools, software platforms, and developers. It
combines SIP and WebRTC calling with messaging, meetings, programmable voice,
and organization-specific AI assistants.

The initial product must support internal communication without depending on a
carrier:

- SIP extension-to-extension voice calls
- Browser and mobile WebRTC calls
- One-to-one chat and group chat
- Audio/video meetings with rooms and invitations
- Organization and extension administration
- Call detail records, presence, recordings, and audit history
- Embeddable APIs and webhooks
- Knowledge-based AI assistants

SIP trunks and billing are deferred from the first release. Their database and
service boundaries should exist now, but no production charging or PSTN route
should be enabled until later phases.

## 2. Current Position

The telecom proof of concept already includes:

- Kamailio on SIP UDP/TCP port `5060`
- FreeSWITCH on SIP port `5080`
- SIP over WSS on port `7443` and WS on port `8080`
- RTPengine with UDP media ports `30000-40000`
- Working SIP registration, routing, echo, and conferencing
- Current echo number `459666`
- Current conference number `45000`
- Public SIP domain `sip.classyra.com.ng`

This proves the core call path, but it is not yet a production SaaS platform.
Tenant isolation, repeatable provisioning, observability, abuse controls,
backups, failover, and automated tests remain product requirements.

The repository currently contains a fresh Laravel application. Although the
original plan names Laravel 12, `composer.json` currently uses Laravel 13.8.
The team should standardize on the repository version before implementation.

Do not publish infrastructure IP addresses, management ports, database access,
or SIP credentials in investor material. The domain and capabilities are
sufficient for external presentations.

## 3. Product Boundaries

### Control plane

Laravel is the system of record for organizations, users, extensions,
permissions, configuration, provisioning status, meetings, AI configuration,
CDRs, recordings metadata, subscriptions, and future billing. React consumes
versioned Laravel APIs. Redis supports queues, cache, presence fan-out, and
rate limiting.

### Signalling and media plane

- **Kamailio:** registration, digest authentication, SIP routing, topology
  hiding, admission control, and dispatching to media nodes.
- **FreeSWITCH:** call applications, IVR, conference mixing, recording, media
  playback, and AI call bridging.
- **RTPengine:** NAT traversal, RTP anchoring, WebRTC/SIP media interworking,
  and codec/security adaptation where configured.
- **Coturn:** TURN relay for WebRTC clients that cannot establish a direct or
  RTPengine-supported network path.

Laravel must never sit in the real-time RTP path. A Laravel outage may delay
administration and CDR ingestion, but established calls should continue.

### Realtime collaboration plane

Messaging and application presence use a WebSocket event service backed by
Redis. SIP signalling is not the chat transport. Messages are persisted in the
application database before events are broadcast. Meeting video requires an
SFU such as LiveKit, Janus, or mediasoup; a FreeSWITCH audio conference alone is
not a scalable Zoom-equivalent video architecture.

### AI plane

RAG integrates with the backend, not directly with Kamailio or RTPengine.
Laravel owns organizations, datasets, permissions, assistant configuration,
conversation records, feedback, and usage. A separately deployable AI service
owns ingestion, chunking, embeddings, retrieval, model calls, and streaming.

For voice assistants, FreeSWITCH sends audio to a real-time voice gateway. The
gateway performs VAD/STT, calls the AI/RAG service, performs TTS, and streams
audio back. Kamailio only routes the SIP session.

```text
Caller -> Kamailio -> FreeSWITCH -> Voice Gateway -> STT
                                             -> AI/RAG API -> tenant knowledge
                                             -> TTS -> FreeSWITCH -> Caller
```

Store the question, retrieved document identifiers, generated answer, latency,
model/version, token or audio usage, outcome, and optional user feedback. Do
not retain raw audio or sensitive prompts without an explicit tenant policy and
retention period.

## 4. Target Architecture

```text
React web / mobile apps / customer integrations
                    |
        HTTPS API + application WebSocket
                    |
     Laravel API and workers (control plane)
          |             |             |
       MariaDB        Redis       Object storage
          |                           recordings/files
          |
 provisioning projection + events/CDRs
          |
SIP/WebRTC -> Kamailio -> FreeSWITCH pool -> RTPengine pool
                   |             |
                usrloc        conference/IVR/AI
                                  |
                           real-time AI gateway
                                  |
                           RAG/LLM services
```

Start as a modular monolith in Laravel with explicit modules and queued
integration jobs. Do not begin with microservices. Extract the AI gateway,
high-volume messaging, or CDR pipeline only when their scaling or runtime needs
justify independent deployment.

## 5. Tenant and Identity Model

Use `organizations` as the neutral tenant term. An organization has members;
a user is a human login identity and may belong to multiple organizations. An
extension is a callable SIP identity owned by one organization and may be
assigned to a user, device, room, queue, or AI assistant.

The extension number is the user-visible callable identifier. A SIP contact is
a temporary device registration maintained by Kamailio's location service; it
is not the extension itself and must not be treated as permanent profile data.

Recommended core tables:

| Table | Purpose |
| --- | --- |
| `organizations` | Tenant profile, status, locale, retention settings |
| `organization_user` | Membership, role, lifecycle state |
| `users` | Human authentication identity |
| `extensions` | Tenant-owned callable number and assignment |
| `sip_credentials` | Auth username, realm, encrypted/hashed credential material |
| `sip_provisioning_states` | Desired/current revision, status, error, synchronized time |
| `devices` | Optional named client/device registrations and push tokens |
| `service_numbers` | Configurable echo, conference, voicemail, AI, and test routes |
| `conversations` | Direct/group chat container |
| `conversation_members` | Conversation membership and read state |
| `messages` | Durable chat messages with idempotency key |
| `meetings` | Scheduled/ad-hoc meeting metadata |
| `meeting_participants` | Invitations, roles, join/leave history |
| `conference_rooms` | FreeSWITCH/SFU room mapping and access policy |
| `calls` | Logical call and tenant ownership |
| `call_legs` | Per-leg SIP identifiers, direction, endpoints, timing, result |
| `call_events` | Normalized append-only call lifecycle events |
| `recordings` | Object key, encryption, duration, consent and retention metadata |
| `webhook_endpoints` | Tenant callback configuration |
| `webhook_deliveries` | Signed delivery attempts and replay state |
| `audit_logs` | Security and administration history |
| `ai_assistants` | Tenant assistant, prompt, voice, status |
| `knowledge_bases` | Tenant-owned knowledge collection |
| `knowledge_sources` | File, URL, text source and ingestion status |
| `ai_interactions` | Question, answer, citations, metrics, feedback |

Reserve these deferred tables without activating workflows:

| Table | Later responsibility |
| --- | --- |
| `plans`, `plan_features` | Entitlements and limits |
| `subscriptions` | Tenant subscription lifecycle |
| `usage_records` | Idempotent metered usage ledger |
| `invoices`, `invoice_items`, `payments` | Billing and payment records |
| `sip_trunks`, `trunk_credentials` | Carrier configuration |
| `phone_numbers` | DIDs, assignment, capabilities |
| `routing_rules` | Inbound/outbound PSTN route policy |
| `rate_cards` | Future per-destination pricing |

Every tenant-owned table must carry `organization_id`, use scoped unique
indexes, and be accessed through tenant-aware policies. Sequential database IDs
should not be exposed as public API identifiers; use UUIDv7/ULID public IDs.

## 6. SIP Provisioning Contract

Laravel owns the desired extension state. `kamailio.subscriber` is an
authentication projection required by the SIP edge, not the business source of
truth. Avoid synchronous controller-level dual writes to two schemas.

Provisioning flow:

1. Validate organization limits and allocate a unique extension.
2. Generate a high-entropy SIP secret and create `extensions`,
   `sip_credentials`, and an outbox event in one application transaction.
3. Return the extension with provisioning state `pending`; reveal the initial
   secret once or offer credential rotation. Never display stored plaintext.
4. A queued worker projects the enabled credential into
   `kamailio.subscriber`, then marks it `active` with the applied revision.
5. Disabling or rotating an extension creates another versioned event.
6. A scheduled reconciler compares desired state with Kamailio and repairs
   missing, stale, or orphaned rows.

Use idempotent upserts and stable event IDs. Failed jobs use exponential backoff
and a dead-letter/admin retry path. If Kamailio and Laravel share MariaDB, use a
dedicated least-privilege database account for the projection worker. Never
give the public API process unrestricted Kamailio database access.

Prefer digest HA1 values when supported by the chosen Kamailio authentication
configuration. If plaintext is operationally unavoidable, encrypt it at rest,
restrict access, rotate it, and never return it after initial issuance. Decide
the credential representation together with the deployed Kamailio `auth_db`
configuration before writing migrations.

## 7. Configurable Service Numbers

Do not hard-code `459666` or `45000` in controllers or SIP clients. Store routes
in `service_numbers` and deploy a generated, versioned routing configuration to
Kamailio/FreeSWITCH.

Suggested fields are `organization_id` (nullable for platform-wide routes),
`type`, `number`, `target`, `enabled`, `configuration`, `version`, and
`provisioning_status`. Initial values:

```text
echo       459666
conference 45000
```

Changing a number should validate collision-free allocation, stage the new
route, reload configuration without terminating active calls, run a synthetic
call test, and only then retire the old route. Keep an optional grace-period
alias to avoid breaking configured clients.

## 8. Primary Workflows

### Internal voice call

1. A client obtains SIP/WebRTC bootstrap configuration from Laravel.
2. It registers to Kamailio using the extension credential.
3. Kamailio authenticates and locates the callee, applying tenant and dial-plan
   policy.
4. RTPengine negotiates media where anchoring/interworking is required.
5. Calls needing recording, conferencing, IVR, queues, or AI are sent through
   FreeSWITCH; a simple direct internal call may be proxy-routed according to
   the final media policy.
6. SIP/FreeSWITCH events are normalized into calls and call legs asynchronously.

### Meeting

Laravel creates the room, participants, role policy, expiry, and join tokens.
FreeSWITCH supplies PSTN/SIP audio; the selected SFU supplies browser/mobile
audio-video and screen sharing. Chat and reactions use the application realtime
plane. Meeting authorization is always tenant-scoped and token-based.

### Customer service

An organization creates a queue, business hours, agents, overflow rules, and
optional AI assistant. Internal extensions can reach it immediately. A future
DID/SIP trunk maps external callers to the same destination without redesigning
the queue domain.

### External integrations

Expose versioned REST APIs, scoped personal/service tokens, idempotency keys,
rate limits, and signed webhooks. Start with extension management, call
initiation, call status, meeting creation, messages, assistant queries, and CDR
events. Publish an OpenAPI contract and webhook replay tooling.

## 9. MVP API Surface

- `/api/v1/auth/*`
- `/api/v1/organizations/*`
- `/api/v1/members/*`
- `/api/v1/extensions/*`
- `/api/v1/extensions/{id}/credentials/rotate`
- `/api/v1/service-numbers/*`
- `/api/v1/calls/*`
- `/api/v1/conversations/*` and `/messages/*`
- `/api/v1/meetings/*` and join-token endpoint
- `/api/v1/conference-rooms/*`
- `/api/v1/recordings/*`
- `/api/v1/assistants/*`
- `/api/v1/knowledge-bases/*`
- `/api/v1/webhooks/*`

Use Sanctum for the first-party web application. Use separately scoped API
tokens or OAuth2 when third-party integrations require delegated access.

## 10. Delivery Roadmap

### Phase 0: Baseline and safety (1-2 weeks)

- Confirm Laravel version, PHP/runtime, MariaDB, Redis, queue worker, and deploy
  process.
- Inventory and version-control sanitized Kamailio, FreeSWITCH, RTPengine, and
  Coturn configuration templates.
- Define SIP realms, extension numbering rules, codec policy, TLS certificates,
  firewall rules, and network diagrams.
- Add staging separate from production, secrets management, backups, and restore
  drills.
- Establish metrics and synthetic registration/echo/conference checks.

**Exit:** reproducible staging deploy and documented rollback; current SIP tests
pass without relying on undocumented server changes.

### Phase 1: Multi-tenant backend (2-3 weeks)

- Organizations, memberships, roles/permissions, audit logs, and Sanctum auth.
- Tenant-scoped extension and service-number models.
- API resources, policies, validation, rate limiting, and OpenAPI baseline.
- Deferred billing and trunk schemas behind disabled feature flags.

**Exit:** tenant isolation tests prove one organization cannot enumerate or
modify another organization's data.

### Phase 2: Reliable SIP lifecycle (2-3 weeks)

- Transactional outbox, queue projection into `kamailio.subscriber`, retries,
  reconciliation, disable, and credential rotation.
- Client bootstrap endpoint for SIP domain, WSS URL, ICE/TURN details, codecs,
  and extension identity.
- Configurable echo/conference number deployment with collision checks.
- Registration state view from Kamailio RPC/DMQ or an event adapter; do not use
  subscriber rows as evidence that a device is online.

**Exit:** create, register, call, rotate, disable, failure/retry, and drift repair
are covered by automated integration tests.

### Phase 3: Calls, CDRs, and recordings (2-3 weeks)

- Consume FreeSWITCH ESL/event socket or HTTP CDR events through a protected
  ingestion adapter.
- Normalize calls, call legs, hangup causes, durations, and recording metadata.
- Store recordings in S3-compatible object storage using short-lived signed
  URLs, encryption, consent policy, and tenant retention jobs.
- Build call history, live status, filters, and export.

**Exit:** CDR ingestion is idempotent and reconciles SIP call IDs across legs;
duplicate events cannot duplicate usage or call records.

### Phase 4: Messaging and presence (3-4 weeks)

- Direct/group conversations, durable messages, attachments, delivery/read
  receipts, application presence, and push notification hooks.
- Redis-backed broadcast infrastructure and offline synchronization cursor.
- Abuse controls, attachment scanning, deletion/retention, and moderation audit.

**Exit:** multi-device delivery and reconnection do not lose or duplicate
messages; tenant and conversation access tests pass.

### Phase 5: Meetings (3-5 weeks)

- Select and deploy an SFU through a short proof of concept and load test.
- Meeting rooms, invitations, roles, expiring join tokens, waiting room,
  mute/remove controls, screen sharing, and meeting chat.
- Bridge SIP/FreeSWITCH audio only where required.

**Exit:** measured concurrency target passes under expected bandwidth and packet
loss; unauthorized users cannot join or reuse expired tokens.

### Phase 6: Business communications (3-5 weeks)

- Queues, agent states, ring groups, IVR, schedules, voicemail, dispositions,
  supervisor controls, and embeddable customer-service APIs.
- Tenant-configurable routing compiled to deployable FreeSWITCH/Kamailio config.

**Exit:** hotel/hospital/support scenarios pass end-to-end, including no-answer,
overflow, offline agent, and failover behavior.

### Phase 7: AI and RAG (4-6 weeks)

- Knowledge source upload, parsing, chunking, embedding, retrieval, citation,
  re-indexing, deletion, and strict tenant filters.
- Text assistant API and tracked interaction/feedback dashboard.
- Real-time voice gateway with interruption/barge-in, latency budgets, fallback
  to human, prompt-injection controls, PII policy, and cost limits.

**Exit:** retrieval evaluations meet agreed groundedness/answer targets; no
cross-tenant retrieval is possible; voice latency and human handoff meet the
service objective.

### Phase 8: Deferred commercial telephony

- Integrate at least two SIP trunk providers for failover.
- DID ordering/import, E.164 normalization, emergency calling policy, fraud
  controls, spend limits, route quality, and carrier reconciliation.
- Activate entitlements, metering, rating, invoices, payments, taxes, credits,
  and webhook-driven payment state.

**Exit:** carrier CDRs reconcile with the immutable usage ledger and billing can
be rerun deterministically without double charging.

### Phase 9: Scale and resilience

- Multiple stateless Kamailio nodes with shared/replicated registration strategy.
- FreeSWITCH and RTPengine pools with health-aware dispatching and draining.
- Redis high availability, MariaDB replication/failover, object storage
  lifecycle, and separated CDR/event ingestion.
- Multi-region design only after latency, residency, or availability data
  justifies it.

**Exit:** documented load, failover, disaster-recovery, and capacity tests meet
defined SLOs rather than an unspecified claim of being carrier-grade.

## 11. Security and Compliance Gates

- TLS/WSS for internet clients; disable public WS and plaintext SIP where client
  compatibility permits. Use SIP-TLS and SRTP for production clients.
- Restrict FreeSWITCH, database, Redis, ESL, RPC, and management interfaces to
  private networks/firewalls. `5080` should not be generally internet-accessible.
- Rate-limit registrations, failed digest authentication, call attempts,
  meetings, messages, and API tokens; deploy SIP flood and toll-fraud alerts.
- Use per-service least-privilege credentials and centralized secret rotation.
- Encrypt recordings, knowledge sources, credentials, and backups; audit access.
- Define consent, retention, export, and deletion rules before recording or AI
  data collection. Requirements vary by customer location and industry and need
  legal review.
- Validate uploaded knowledge documents and attachments before parsing.
- Sign webhooks and require idempotency for all externally triggered mutations.

## 12. Observability and Capacity

Track API latency/errors, queue depth/age, provisioning lag, registrations,
INVITE response codes, setup time, concurrent calls, RTP packet loss/jitter,
one-way/no-audio detections, FreeSWITCH sessions, conference participants, AI
latency, retrieval failures, and webhook delivery health.

Correlate every call using a platform call ID plus SIP `Call-ID`, FreeSWITCH
UUIDs, organization ID, and call-leg IDs. Never use phone numbers or SIP secrets
as metric labels.

Define initial service objectives after baseline measurement, then load test
registration bursts, calls per second, concurrent media, conference mixing,
WebSocket connections, message fan-out, and AI concurrency. Scale from measured
bottlenecks. Laravel is normally outside media, but slow provisioning, token
issuance, or event ingestion can still damage the product experience.

## 13. Immediate Engineering Backlog

1. Decide Laravel 13 versus Laravel 12 and document supported runtime versions.
2. Capture sanitized, reproducible telecom configuration and a staging topology.
3. Finalize the tenant, user, membership, extension, and SIP credential rules.
4. Confirm Kamailio `subscriber` schema and digest storage mode.
5. Agree extension allocation scope: globally unique or unique per SIP realm.
6. Implement Phase 1 migrations, models, policies, APIs, and tenant isolation
   tests.
7. Implement the transactional provisioning outbox and a fake Kamailio adapter
   before connecting staging.
8. Add real Kamailio projection, reconciliation, and end-to-end SIP tests.
9. Implement configurable service numbers and staged config deployment.
10. Add CDR event ingestion before dashboard call-history work.

## 14. Product Decisions Needed Before Development

- Whether a human can belong to multiple organizations
- Whether extension numbers are global, per organization, or per SIP realm
- Whether direct calls always anchor media for recording/security consistency
- Required clients at launch: browser, Android, iOS, or third-party softphones
- Whether MVP meetings require video and screen sharing or audio only
- Recording consent and default retention policy
- Regions and regulated customer types supported at launch
- AI model/embedding provider, data residency, and whether tenant data may leave
  the deployment region
- First measurable launch target for registered devices, concurrent calls,
  meeting participants, and messages per second

These decisions affect schema constraints, dial plans, infrastructure sizing,
compliance, and client implementation. They should be recorded as architecture
decision records, not left implicit in server configuration.
