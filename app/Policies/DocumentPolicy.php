<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->can('files.view');
    }

    public function create(User $user): bool
    {
        return $user->can('docs.create');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('docs.edit_realtime');
    }

        public function delete(User $user, Document $document): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }
}
