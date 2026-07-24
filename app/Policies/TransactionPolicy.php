<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Transaction $transaction): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function update(User $user, Transaction $transaction): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function delete(User $user, Transaction $transaction): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }

    public function deleteAny(User $user): bool
    {
        return $user->currentRole()?->canWrite() ?? false;
    }
}
