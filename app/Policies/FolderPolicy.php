<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

class FolderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('files.view');
    }

    public function create(User $user): bool
    {
        return $user->can('folders.create');
    }

    public function delete(User $user, Folder $folder): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }

    public function restore(User $user, Folder $folder): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }

    public function forceDelete(User $user, Folder $folder): bool
    {
        return $user->hasRole('admin') || $user->can('files.delete');
    }
}
