<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConferenceRooms\CreateConferenceRoom;
use App\Actions\ConferenceRooms\EndConferenceRoom;
use App\Actions\ConferenceRooms\InviteConferenceRoomParticipant;
use App\Actions\ConferenceRooms\JoinConferenceRoom;
use App\Actions\ConferenceRooms\LeaveConferenceRoom;
use App\Actions\ConferenceRooms\ModerateConferenceRoomParticipant;
use App\Actions\ConferenceRooms\ModerateConferenceRoomParticipantMedia;
use App\Actions\ConferenceRooms\RemoveConferenceRoomParticipant;
use App\Actions\ConferenceRooms\RequestConferenceRoomEntry;
use App\Actions\ConferenceRooms\TouchConferenceRoomExpiry;
use App\Enums\ConferenceParticipantStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteConferenceRoomParticipantRequest;
use App\Http\Requests\Api\V1\JoinConferenceRoomByInviteRequest;
use App\Http\Requests\Api\V1\JoinConferenceRoomRequest;
use App\Http\Requests\Api\V1\LeaveConferenceRoomByInviteRequest;
use App\Http\Requests\Api\V1\ModerateConferenceRoomParticipantRequest;
use App\Http\Requests\Api\V1\ResolveConferenceRoomInviteRequest;
use App\Http\Requests\Api\V1\StoreConferenceRoomRequest;
use App\Http\Resources\Api\V1\ConferenceRoomParticipantResource;
use App\Http\Resources\Api\V1\ConferenceRoomResource;
use App\Models\ConferenceRoom;
use App\Models\ConferenceRoomParticipant;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ConferenceRoomController extends Controller
{
    public function __construct(
        private CreateConferenceRoom $createConferenceRoom,
        private InviteConferenceRoomParticipant $inviteConferenceRoomParticipant,
        private JoinConferenceRoom $joinConferenceRoom,
        private LeaveConferenceRoom $leaveConferenceRoom,
        private EndConferenceRoom $endConferenceRoom,
        private RequestConferenceRoomEntry $requestConferenceRoomEntry,
        private ModerateConferenceRoomParticipant $moderateConferenceRoomParticipant,
        private ModerateConferenceRoomParticipantMedia $moderateConferenceRoomParticipantMedia,
        private RemoveConferenceRoomParticipant $removeConferenceRoomParticipant,
        private TouchConferenceRoomExpiry $touchConferenceRoomExpiry,
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ConferenceRoom::class, $organization]);

        $conferenceRooms = $organization->conferenceRooms()
            ->select('conference_rooms.*')
            ->with(['organization', 'hostUser', 'endedByUser', 'participants.user'])
            ->withCount('participants')
            ->latest()
            ->paginate(25);

        return ConferenceRoomResource::collection($conferenceRooms);
    }

    public function store(StoreConferenceRoomRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('create', [ConferenceRoom::class, $organization]);

        $conferenceRoom = $this->createConferenceRoom->execute(
            $organization,
            $request->user(),
            [
                'title' => $request->string('title')->toString(),
                'passcode' => $request->input('passcode'),
                'expires_at' => $request->filled('expires_in_minutes')
                    ? now()->addMinutes((int) $request->integer('expires_in_minutes'))
                    : null,
                'configuration' => $request->input('configuration'),
            ],
        );

        return ConferenceRoomResource::make($conferenceRoom->loadCount('participants'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function resolve(ResolveConferenceRoomInviteRequest $request): ConferenceRoomResource
    {
        $conferenceRoom = $this->resolveConferenceRoomByInviteCode($request->string('code')->toString());
        $conferenceRoom = $this->ensureConferenceRoomAvailable($conferenceRoom);

        Gate::authorize('resolveInvite', $conferenceRoom);

        return ConferenceRoomResource::make($this->attachInviteContext($conferenceRoom, $request->user()));
    }

    public function show(Organization $organization, ConferenceRoom $conferenceRoom): ConferenceRoomResource
    {
        Gate::authorize('view', $conferenceRoom);

        return ConferenceRoomResource::make(
            $conferenceRoom->load(['organization', 'hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
        );
    }

    public function join(
        JoinConferenceRoomRequest $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): JsonResponse|ConferenceRoomResource {
        Gate::authorize('join', $conferenceRoom);

        $conferenceRoom = $this->ensureConferenceRoomAvailable($conferenceRoom);

        if (! $this->canJoinDirectly($conferenceRoom, $request->user())) {
            $participant = $this->requestConferenceRoomEntry->execute(
                $conferenceRoom,
                $request->user(),
                $request->validated(),
            );

            $conferenceRoom = $this->attachInviteContext(
                $conferenceRoom->fresh(['organization', 'hostUser', 'endedByUser']),
                $request->user(),
                $participant,
            );

            return ConferenceRoomResource::make($conferenceRoom)
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED);
        }

        $this->joinConferenceRoom->execute(
            $conferenceRoom,
            $request->user(),
            $request->validated(),
        );

        $conferenceRoom = $conferenceRoom
            ->fresh(['organization', 'hostUser', 'endedByUser', 'participants.user'])
            ->loadCount('participants');
        $conferenceRoom->setAttribute('join_sip_number', $conferenceRoom->sip_number);

        return ConferenceRoomResource::make($conferenceRoom);
    }

    public function joinByInvite(JoinConferenceRoomByInviteRequest $request): JsonResponse|ConferenceRoomResource
    {
        $conferenceRoom = $this->resolveConferenceRoomByInviteCode($request->string('invite_code')->toString());
        $conferenceRoom = $this->ensureConferenceRoomAvailable($conferenceRoom);

        Gate::authorize('resolveInvite', $conferenceRoom);

        if (! $this->canJoinDirectly($conferenceRoom, $request->user())) {
            $participant = $this->requestConferenceRoomEntry->execute(
                $conferenceRoom,
                $request->user(),
                $request->validated(),
            );

            $conferenceRoom = $this->attachInviteContext(
                $conferenceRoom->fresh(['organization', 'hostUser', 'endedByUser']),
                $request->user(),
                $participant,
            );

            return ConferenceRoomResource::make($conferenceRoom)
                ->response()
                ->setStatusCode(Response::HTTP_ACCEPTED);
        }

        $this->joinConferenceRoom->execute(
            $conferenceRoom,
            $request->user(),
            $request->validated(),
        );

        $conferenceRoom = $this->attachInviteContext(
            $conferenceRoom
                ->fresh(['organization', 'hostUser', 'endedByUser', 'participants.user'])
                ->loadCount('participants'),
            $request->user(),
        );
        $conferenceRoom->setAttribute('join_sip_number', $conferenceRoom->sip_number);

        return ConferenceRoomResource::make($conferenceRoom);
    }

    public function leaveByInvite(LeaveConferenceRoomByInviteRequest $request): ConferenceRoomResource
    {
        $conferenceRoom = $this->resolveConferenceRoomByInviteCode($request->string('invite_code')->toString());

        Gate::authorize('resolveInvite', $conferenceRoom);

        $this->leaveConferenceRoom->execute($conferenceRoom, $request->user());

        return ConferenceRoomResource::make(
            $this->attachInviteContext(
                $conferenceRoom
                    ->fresh(['organization', 'hostUser', 'endedByUser', 'participants.user'])
                    ->loadCount('participants'),
                $request->user(),
            ),
        );
    }

    public function waitingParticipants(
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): AnonymousResourceCollection {
        Gate::authorize('viewWaitingRoom', $conferenceRoom);

        $participants = $conferenceRoom->participants()
            ->with('user')
            ->where('status', ConferenceParticipantStatus::Waiting->value)
            ->latest('invited_at')
            ->get();

        return ConferenceRoomParticipantResource::collection($participants);
    }

    public function admitParticipant(
        ModerateConferenceRoomParticipantRequest $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, $request);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipant->admit(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function denyParticipant(
        ModerateConferenceRoomParticipantRequest $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, $request);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipant->deny(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function removeParticipant(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, null);

        return ConferenceRoomParticipantResource::make(
            $this->removeConferenceRoomParticipant->execute(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function muteParticipant(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, null);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipantMedia->muteAudio(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function unmuteParticipant(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, null);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipantMedia->unmuteAudio(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function videoOffParticipant(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, null);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipantMedia->muteVideo(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function videoOnParticipant(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $participant,
    ): JsonResource {
        Gate::authorize('moderateParticipant', $conferenceRoom);
        $this->ensureParticipantBelongsToRoom($conferenceRoom, $participant, null);

        return ConferenceRoomParticipantResource::make(
            $this->moderateConferenceRoomParticipantMedia->unmuteVideo(
                $conferenceRoom,
                $participant,
                $request->user(),
            ),
        );
    }

    public function invite(
        InviteConferenceRoomParticipantRequest $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): ConferenceRoomResource {
        Gate::authorize('invite', $conferenceRoom);

        $this->inviteConferenceRoomParticipant->execute($conferenceRoom, $request->validated());

        return ConferenceRoomResource::make(
            $conferenceRoom->fresh(['organization', 'hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
        );
    }

    public function leave(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): ConferenceRoomResource {
        Gate::authorize('leave', $conferenceRoom);

        $this->leaveConferenceRoom->execute($conferenceRoom, $request->user());

        return ConferenceRoomResource::make(
            $conferenceRoom->fresh(['organization', 'hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
        );
    }

    public function end(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): ConferenceRoomResource {
        Gate::authorize('end', $conferenceRoom);

        return ConferenceRoomResource::make(
            $this->endConferenceRoom->execute($conferenceRoom, $request->user())
                ->load(['organization', 'hostUser', 'endedByUser', 'participants.user'])
                ->loadCount('participants'),
        );
    }

    private function resolveConferenceRoomByInviteCode(string $inviteCode): ConferenceRoom
    {
        $conferenceRoom = ConferenceRoom::query()
            ->select('conference_rooms.*')
            ->with(['organization', 'hostUser', 'endedByUser'])
            ->where('invite_code', $inviteCode)
            ->first();

        if ($conferenceRoom === null) {
            throw (new ModelNotFoundException)->setModel(ConferenceRoom::class);
        }

        return $conferenceRoom;
    }

    private function attachInviteContext(
        ConferenceRoom $conferenceRoom,
        User $user,
        ?ConferenceRoomParticipant $participant = null,
    ): ConferenceRoom {
        $participant ??= $conferenceRoom->participants()
            ->with('user')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($this->shouldExposeInviteRoster($participant)) {
            $conferenceRoom->loadMissing(['participants.user']);
            $conferenceRoom->loadCount('participants');
        }

        $conferenceRoom->setRelation('currentUserParticipant', $participant);
        $conferenceRoom->setAttribute('can_join_directly', $this->canJoinDirectly($conferenceRoom, $user, $participant));
        $conferenceRoom->setAttribute('waiting_room_required', ! $conferenceRoom->getAttribute('can_join_directly'));

        return $conferenceRoom;
    }

    private function canJoinDirectly(
        ConferenceRoom $conferenceRoom,
        User $user,
        ?ConferenceRoomParticipant $participant = null,
    ): bool {
        if ($conferenceRoom->host_user_id === $user->id) {
            return true;
        }

        if ($conferenceRoom->isOpen()) {
            return true;
        }

        $participant ??= $conferenceRoom->participants()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        return in_array(
            $participant?->status,
            [ConferenceParticipantStatus::Invited, ConferenceParticipantStatus::Joined],
            true,
        );
    }

    private function ensureConferenceRoomAvailable(ConferenceRoom $conferenceRoom): ConferenceRoom
    {
        $loadedRelations = array_keys($conferenceRoom->getRelations());

        $conferenceRoom = $this->touchConferenceRoomExpiry->execute($conferenceRoom);
        $conferenceRoom->refresh();

        if ($loadedRelations !== []) {
            $conferenceRoom->loadMissing($loadedRelations);
        }

        if ($conferenceRoom->status->value === 'expired') {
            throw ValidationException::withMessages([
                'conference_room' => 'This meeting invite has expired.',
            ]);
        }

        if ($conferenceRoom->status->value !== 'active') {
            throw ValidationException::withMessages([
                'conference_room' => 'This meeting has ended.',
            ]);
        }

        return $conferenceRoom;
    }

    private function shouldExposeInviteRoster(?ConferenceRoomParticipant $participant): bool
    {
        return in_array(
            $participant?->status,
            [ConferenceParticipantStatus::Invited, ConferenceParticipantStatus::Joined],
            true,
        );
    }

    private function ensureParticipantBelongsToRoom(
        ConferenceRoom $conferenceRoom,
        ConferenceRoomParticipant $conferenceParticipant,
        ?Request $request,
    ): void {
        if ($conferenceParticipant->conference_room_id !== $conferenceRoom->id) {
            throw (new ModelNotFoundException)->setModel(ConferenceRoomParticipant::class);
        }

        if ($request !== null && $request->string('invite_code')->toString() !== $conferenceRoom->invite_code) {
            throw ValidationException::withMessages([
                'invite_code' => 'The meeting invite code is invalid.',
            ]);
        }
    }
}
