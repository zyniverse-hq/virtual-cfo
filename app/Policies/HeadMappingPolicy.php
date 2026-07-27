<?php

namespace App\Policies;

use App\Models\HeadMapping;
use App\Models\User;

class HeadMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, HeadMapping $headMapping): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function update(User $user, HeadMapping $headMapping): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function delete(User $user, HeadMapping $headMapping): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }
}
