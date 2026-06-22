<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ConferenceRooms\CreateConferenceRoom;
use App\Actions\ConferenceRooms\EndConferenceRoom;
use App\Actions\ConferenceRooms\InviteConferenceRoomParticipant;
use App\Actions\ConferenceRooms\JoinConferenceRoom;
use App\Actions\ConferenceRooms\LeaveConferenceRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\InviteConferenceRoomParticipantRequest;
use App\Http\Requests\Api\V1\JoinConferenceRoomRequest;
use App\Http\Requests\Api\V1\StoreConferenceRoomRequest;
use App\Http\Resources\Api\V1\ConferenceRoomResource;
use App\Models\ConferenceRoom;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ConferenceRoomController extends Controller
{
    public function __construct(
        private CreateConferenceRoom $createConferenceRoom,
        private InviteConferenceRoomParticipant $inviteConferenceRoomParticipant,
        private JoinConferenceRoom $joinConferenceRoom,
        private LeaveConferenceRoom $leaveConferenceRoom,
        private EndConferenceRoom $endConferenceRoom,
    ) {}

    public function index(Organization $organization): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [ConferenceRoom::class, $organization]);

        $conferenceRooms = $organization->conferenceRooms()
            ->with(['hostUser', 'endedByUser', 'participants.user'])
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

    public function show(Organization $organization, ConferenceRoom $conferenceRoom): ConferenceRoomResource
    {
        Gate::authorize('view', $conferenceRoom);

        return ConferenceRoomResource::make(
            $conferenceRoom->load(['hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
        );
    }

    public function join(
        JoinConferenceRoomRequest $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): ConferenceRoomResource {
        Gate::authorize('join', $conferenceRoom);

        $this->joinConferenceRoom->execute(
            $conferenceRoom,
            $request->user(),
            $request->validated(),
        );

        return ConferenceRoomResource::make(
            $conferenceRoom->fresh(['hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
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
            $conferenceRoom->fresh(['hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
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
            $conferenceRoom->fresh(['hostUser', 'endedByUser', 'participants.user'])->loadCount('participants'),
        );
    }

    public function end(
        Request $request,
        Organization $organization,
        ConferenceRoom $conferenceRoom,
    ): ConferenceRoomResource {
        Gate::authorize('end', $conferenceRoom);

        return ConferenceRoomResource::make(
            $this->endConferenceRoom->execute($conferenceRoom, $request->user())->loadCount('participants'),
        );
    }
}
