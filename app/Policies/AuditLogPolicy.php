<?php

namespace App\Policies;

use App\Models\User;

class AuditLogPolicy
{
    /**
     * Solo los administradores consultan la auditoría.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
