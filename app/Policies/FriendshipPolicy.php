<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;

class FriendshipPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Friendship $friendship): bool
    {
        return $friendship->requester_id === $user->id || $friendship->addressee_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Friendship $friendship): bool
    {
        return $friendship->addressee_id === $user->id;
    }

    public function delete(User $user, Friendship $friendship): bool
    {
        return $friendship->requester_id === $user->id || $friendship->addressee_id === $user->id;
    }

    public function restore(User $user, Friendship $friendship): bool
    {
        return false;
    }

    public function forceDelete(User $user, Friendship $friendship): bool
    {
        return false;
    }
}
