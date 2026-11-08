<?php

namespace App\Policies;

use App\Models\ReceivedEmail;
use App\Models\User;

/**
 * Bandeja "Correos recibidos" del panel (NP-46 paso 7): solo administradores.
 * Los correos entran únicamente por php artisan mail:receive y no se borran
 * desde el panel.
 */
class ReceivedEmailPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ReceivedEmail $correo): bool
    {
        return $user->isAdmin();
    }

    /**
     * Solo marcar como leído / no leído (el contenido del correo no se edita).
     */
    public function update(User $user, ReceivedEmail $correo): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, ReceivedEmail $correo): bool
    {
        return false;
    }
}
