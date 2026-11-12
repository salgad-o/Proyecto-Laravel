<?php

namespace App\Policies;

use App\Models\Noticia;
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

    /**
     * Ver la actividad PROPIA (registros con user_id = el suyo), por ejemplo en el
     * panel lateral de /dashboard (NP-21). La tiene quien puede entrar al panel:
     * admin y editor (misma regla que NoticiaPolicy::viewPanel); el lector no.
     * No da acceso a la auditoría completa (eso es viewAny).
     */
    public function viewOwn(User $user): bool
    {
        return $user->can('viewPanel', Noticia::class);
    }
}
