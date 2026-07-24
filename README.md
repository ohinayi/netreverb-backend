# NetReverb

A Laravel (PHP 8.4) + Vue 3 communications platform: SIP/WebRTC calling, HD video conferencing, direct and group messaging, and organization/telephony administration, built on FreeSWITCH.

- **Backend**: this repo — Laravel 13, MySQL, FreeSWITCH ESL integration, Laravel Sanctum auth.
- **Frontend**: `netreverb-frontend` (sibling repo) — Vue 3, TypeScript, Tailwind CSS v4, SIP.js, TanStack Query.

## Done

**Calling & conferencing**
- 1:1 and organization dial-pad audio/video calling over SIP.js, backed by per-user FreeSWITCH extensions.
- Multi-party video conference rooms (`ConferenceRoom`), including host-controlled waiting room, admit/deny, mute/unmute, camera on/off with per-participant avatar fallback when a camera is off, raised-hand with auto-lower and a visual badge, and emoji reactions.
- Screen sharing implemented as a dual SIP leg (primary camera leg + dedicated screen-share leg) so a presenter's own camera feed keeps working while they share — avoids the single-leg approach's video-replacement bugs. Host can revoke a participant's screen-share mid-meeting.
- Conference presence reconciliation with a grace period, so flaky connections don't instantly evict participants, while genuinely dropped participants are still cleaned up.
- In-conference text chat.
- Conference invite links/codes, join-by-invite, and recordings.

**Messaging**
- Direct and group conversations (`Conversation.kind`: direct/group/community), with a member-picker dialog for starting new 1:1 or group chats.
- Call icons in a conversation header route straight into placing a real audio/video call (resolves the other participant's SIP extension automatically) instead of a dead-end UI.
- Group conversations can be escalated straight into a conference room, auto-inviting every group member.

**Organizations & administration**
- Multi-tenant organizations with membership roles (owner/admin/member) and status (invited/active/suspended).
- Organization-scoped departments: create, list, update, and assign members to a department.
- Add a member to an organization by existing account or by email — inviting by email auto-creates the account and emails a password-setup link.
- Extensions, service numbers, and dialable numbers per organization; call logs with recording start/stop/upload/finalize.

**Design**
- Full visual redesign of the landing page, login/register, sidebar/nav, and messaging UI (Lumina Blue `#2563eb` brand, real light and dark themes, theme defaults to the OS preference).

## Pending / Roadmap

- **Call transfer** — blind/attended transfer between users and departments. Department infrastructure (this release) is the prerequisite; the live SIP transfer logic itself is not yet built.
- **AI integration** — general AI-assisted features across calling and conferencing.
- **Transcription** — real-time transcription of calls and conference sessions.
- **Translation** — real-time translation on calls and in conferences.
- **AI customer-service assistant** — an agent that can answer questions on a call, draft responses, and create orders on the caller's behalf.
- **SIP trunk & DID numbers** — connecting a SIP trunk for real PSTN numbers, DID number assignment, and call management around it.
- **Call audit log** — a dedicated audit trail of call activity beyond the existing `CallLog` record.

## Next phase

Transcription is the natural next step: it's a direct prerequisite for both translation (translate the transcript) and the AI customer-service assistant (the assistant needs a text stream of the call to reason over), so building it first unblocks the largest share of the remaining roadmap. Call transfer is the next-highest-leverage item after that, since the department structure it needs already exists.

## Local development

```bash
composer install
php artisan migrate
composer run dev   # serves the app + queue worker + Vite watcher
```

Run tests with `php artisan test --compact`. Format PHP with `vendor/bin/pint`.

See `CLAUDE.md` for the project's coding conventions (Laravel Boost guidelines).
