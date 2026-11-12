<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Panel de usuarios: solo administradores.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Un admin no cambia su propio rol: no puede quitarse el acceso por error
     * y quien hace el cambio sigue siendo admin, así que siempre queda al menos uno.
     */
    public function updateRole(User $actor, User $usuario): bool
    {
        return $actor->isAdmin() && $actor->id !== $usuario->id;
    }

    /**
     * Un admin no se desactiva a sí mismo, por la misma razón que en updateRole().
     */
    public function updateStatus(User $actor, User $usuario): bool
    {
        return $actor->isAdmin() && $actor->id !== $usuario->id;
    }

    /**
     * Decisión del proyecto: los usuarios se desactivan, nunca se borran.
     * Así la auditoría conserva quién hizo cada acción.
     */
    public function delete(User $actor, User $usuario): bool
    {
        return false;
    }
}
