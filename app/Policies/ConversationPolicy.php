<?php

namespace App\Policies;

use App\Enums\ConversationKind;
use App\Enums\FriendshipStatus;
use App\Enums\MessageRequestStatus;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Friendship;
use App\Models\MessageRequest;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->exists();

        if (! $isParticipant) {
            return false;
        }

        if ($conversation->kind === ConversationKind::Direct) {
            $otherParticipant = ConversationParticipant::query()
                ->where('conversation_id', $conversation->id)
                ->where('user_id', '!=', $user->id)
                ->first();

            if ($otherParticipant !== null) {
                $otherUserId = $otherParticipant->user_id;

                $isFriend = Friendship::query()
                    ->where('status', FriendshipStatus::Accepted)
                    ->where(function ($query) use ($user, $otherUserId): void {
                        $query->where(function ($q) use ($user, $otherUserId): void {
                            $q->where('requester_id', $user->id)
                                ->where('addressee_id', $otherUserId);
                        })->orWhere(function ($q) use ($user, $otherUserId): void {
                            $q->where('requester_id', $otherUserId)
                                ->where('addressee_id', $user->id);
                        });
                    })
                    ->exists();

                $hasAcceptedRequest = MessageRequest::query()
                    ->where('status', MessageRequestStatus::Accepted)
                    ->where(function ($query) use ($user, $otherUserId): void {
                        $query->where(function ($q) use ($user, $otherUserId): void {
                            $q->where('sender_user_id', $user->id)
                                ->where('recipient_user_id', $otherUserId);
                        })->orWhere(function ($q) use ($user, $otherUserId): void {
                            $q->where('sender_user_id', $otherUserId)
                                ->where('recipient_user_id', $user->id);
                        });
                    })
                    ->exists();

                return $isFriend || $hasAcceptedRequest;
            }
        }

        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }

    public function restore(User $user, Conversation $conversation): bool
    {
        return false;
    }

    public function forceDelete(User $user, Conversation $conversation): bool
    {
        return false;
    }
}
