<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\MembershipStatus;
use App\Enums\TicketMessageType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TicketMessageResource;
use App\Http\Resources\Api\V1\TicketResource;
use App\Models\CallLog;
use App\Models\Organization;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $this->authorizeOrganization($organization);

        $status = $request->string('status')->toString();

        $tickets = $organization->tickets()
            ->with(['assignee', 'creator', 'callLog'])
            ->withCount('messages')
            ->when(
                $status !== '' && in_array($status, array_column(TicketStatus::cases(), 'value'), true),
                fn ($query) => $query->where('status', $status)
            )
            ->latest()
            ->paginate(20);

        return TicketResource::collection($tickets);
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeOrganization($organization);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'priority' => ['nullable', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'due_at' => ['nullable', 'date'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'call_log_id' => ['nullable', 'string', Rule::exists('call_logs', 'public_id')->where(fn ($query) => $query->where('organization_id', $organization->id))],
            'lead_id' => ['nullable', 'string', Rule::exists('leads', 'public_id')->where(fn ($query) => $query->where('organization_id', $organization->id))],
            'assignee_user_id' => ['nullable', 'string', $this->activeMemberRule($organization)],
            'message' => ['nullable', 'string', 'max:5000'],
        ]);

        if (! empty($data['call_log_id'])) {
            $data['call_log_id'] = CallLog::where('public_id', $data['call_log_id'])->value('id');
        }
        if (! empty($data['lead_id'])) {
            $data['lead_id'] = \App\Models\Lead::where('public_id', $data['lead_id'])->value('id');
        }
        if (! empty($data['assignee_user_id'])) {
            $data['assignee_user_id'] = \App\Models\User::where('public_id', $data['assignee_user_id'])->value('id');
        }

        $message = $data['message'] ?? null;
        unset($data['message']);

        $ticket = $organization->tickets()->create([
            ...$data,
            'status' => TicketStatus::Open,
            'priority' => $data['priority'] ?? TicketPriority::Medium,
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        if ($message !== null && trim($message) !== '') {
            $ticket->messages()->create([
                'author_user_id' => $request->user()->getKey(),
                'type' => TicketMessageType::Customer,
                'body' => $message,
            ]);
        }

        return TicketResource::make($ticket->load(['assignee', 'creator', 'callLog', 'messages.author']))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Organization $organization, Ticket $ticket): TicketResource
    {
        $this->authorizeOrganization($organization);
        abort_unless($ticket->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        return TicketResource::make($ticket->load(['assignee', 'creator', 'callLog', 'messages.author']));
    }

    public function update(Request $request, Organization $organization, Ticket $ticket): TicketResource
    {
        $this->authorizeOrganization($organization);
        abort_unless($ticket->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(array_column(TicketStatus::cases(), 'value'))],
            'priority' => ['sometimes', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'assignee_user_id' => ['nullable', 'string', $this->activeMemberRule($organization)],
        ]);

        if (array_key_exists('assignee_user_id', $data)) {
            $data['assignee_user_id'] = $data['assignee_user_id'] !== null
                ? \App\Models\User::where('public_id', $data['assignee_user_id'])->value('id')
                : null;
        }

        $ticket->update($data);

        return TicketResource::make($ticket->load(['assignee', 'creator', 'callLog', 'messages.author']));
    }

    public function bulkUpdate(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $this->authorizeOrganization($organization);

        $data = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1', 'max:100'],
            'ticket_ids.*' => ['string'],
            'status' => ['sometimes', Rule::in(array_column(TicketStatus::cases(), 'value'))],
            'priority' => ['sometimes', Rule::in(array_column(TicketPriority::cases(), 'value'))],
            'assignee_user_id' => ['sometimes', 'nullable', 'string', $this->activeMemberRule($organization)],
        ]);

        $tickets = $organization->tickets()->whereIn('public_id', $data['ticket_ids'])->get();

        $updates = array_intersect_key($data, array_flip(['status', 'priority']));
        if (array_key_exists('assignee_user_id', $data)) {
            $updates['assignee_user_id'] = $data['assignee_user_id'] !== null
                ? \App\Models\User::where('public_id', $data['assignee_user_id'])->value('id')
                : null;
        }

        if ($updates !== []) {
            foreach ($tickets as $ticket) {
                $ticket->update($updates);
            }
        }

        return TicketResource::collection($tickets->fresh(['assignee', 'creator', 'callLog']));
    }

    public function destroy(Organization $organization, Ticket $ticket): Response
    {
        $this->authorizeOrganization($organization);
        abort_unless($ticket->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        $ticket->delete();

        return response()->noContent();
    }

    public function messages(Organization $organization, Ticket $ticket): AnonymousResourceCollection
    {
        $this->authorizeOrganization($organization);
        abort_unless($ticket->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        return TicketMessageResource::collection($ticket->messages()->with('author')->get());
    }

    public function storeMessage(Request $request, Organization $organization, Ticket $ticket): JsonResponse
    {
        $this->authorizeOrganization($organization);
        abort_unless($ticket->organization_id === $organization->id, Response::HTTP_NOT_FOUND);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'type' => ['nullable', Rule::in(array_column(TicketMessageType::cases(), 'value'))],
        ]);

        $message = $ticket->messages()->create([
            'author_user_id' => $request->user()->getKey(),
            'type' => $data['type'] ?? TicketMessageType::AgentReply,
            'body' => $data['body'],
        ]);

        return TicketMessageResource::make($message->load('author'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    private function activeMemberRule(Organization $organization): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists('users', 'public_id')->where(function ($query) use ($organization): void {
            $query->whereExists(function ($sub) use ($organization): void {
                $sub->selectRaw('1')
                    ->from('organization_memberships')
                    ->whereColumn('organization_memberships.user_id', 'users.id')
                    ->where('organization_memberships.organization_id', $organization->id)
                    ->where('organization_memberships.status', MembershipStatus::Active->value);
            });
        });
    }

    private function authorizeOrganization(Organization $organization): void
    {
        abort_if($organization->isPersonalWorkspace(), 404);
        $membership = request()->user()?->organizations()->whereKey($organization->getKey())->first();
        abort_unless($membership, 403);
    }
}
