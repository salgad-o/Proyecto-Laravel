<?php

namespace App\Policies;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;

class ComentarioPolicy
{
    /**
     * Panel de moderación: solo administradores.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Cualquier usuario autenticado comenta, pero solo noticias publicadas.
     * Uso: Gate::authorize('create', [Comentario::class, $noticia])
     */
    public function create(User $user, Noticia $noticia): bool
    {
        return $noticia->status === 'publicada';
    }

    /**
     * Solo el autor edita su comentario (ni siquiera el admin cambia palabras ajenas).
     */
    public function update(User $user, Comentario $comentario): bool
    {
        return $user->id === $comentario->user_id;
    }

    /**
     * El autor elimina el suyo; el administrador modera cualquiera.
     */
    public function delete(User $user, Comentario $comentario): bool
    {
        return $user->id === $comentario->user_id || $user->isAdmin();
    }
}
