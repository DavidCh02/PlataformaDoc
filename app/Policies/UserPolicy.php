<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function manage(User $user): bool
    {
        return $user->can('users.manage');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('users.manage') && $actor->id !== $target->id;
    }
}
