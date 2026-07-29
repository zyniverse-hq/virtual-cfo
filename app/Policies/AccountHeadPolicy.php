<?php

namespace App\Policies;

use App\Models\AccountHead;
use App\Models\User;

class AccountHeadPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AccountHead $accountHead): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function update(User $user, AccountHead $accountHead): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function delete(User $user, AccountHead $accountHead): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }
}
