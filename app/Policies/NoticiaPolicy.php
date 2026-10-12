<?php

namespace App\Policies;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class NoticiaPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * Publicadas: cualquiera. Borradores: su autor y los administradores.
     */
    public function view(?User $user, Noticia $noticia): bool
    {
        if ($noticia->status === 'publicada') {
            return true;
        }

        return $user !== null
            && ($user->isAdmin() || $user->id === $noticia->user_id);
    }

    /**
     * Determine whether the user can create models.
     * Solo administradores y editores.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'editor');
    }

    /**
     * Determine whether the user can update the model.
     * Administrador: cualquiera. Editor: solo las suyas.
     */
    public function update(User $user, Noticia $noticia): bool
    {
        return $user->isAdmin()
            || ($user->hasRole('editor') && $user->id === $noticia->user_id);
    }

    /**
     * Determine whether the user can delete the model.
     * Mismas reglas que actualizar.
     */
    public function delete(User $user, Noticia $noticia): bool
    {
        return $user->isAdmin()
            || ($user->hasRole('editor') && $user->id === $noticia->user_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Noticia $noticia): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Noticia $noticia): bool
    {
        return $user->isAdmin();
    }
}