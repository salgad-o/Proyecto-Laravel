<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    /**
     * Solo los administradores ven la gestión de roles.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Solo los administradores editan el nombre visible y la descripción.
     */
    public function update(User $user, Role $role): bool
    {
        return $user->isAdmin();
    }

    /**
     * Decisión del proyecto: los roles no se eliminan. Si en el futuro se
     * agregara una ruta, esta Policy seguiría bloqueando el borrado.
     */
    public function delete(User $user, Role $role): bool
    {
        return false;
    }
}
