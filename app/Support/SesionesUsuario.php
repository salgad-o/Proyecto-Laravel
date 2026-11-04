<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones sobre las sesiones guardadas de un usuario (NP-36).
 *
 * IMPORTANTE: depende de SESSION_DRIVER=database (el valor de .env.example).
 * Con otro driver (file, cookie, array, redis...) no hay filas en la tabla de
 * sesiones y estos métodos no cierran nada: devuelven 0.
 *
 * No usamos Auth::logoutOtherDevices(): solo invalida otras sesiones si el
 * middleware AuthenticateSession está activo, y en este proyecto no lo está.
 *
 * Posible refactor: PasswordResetController@reset (NP-4) borra las sesiones
 * del usuario con la misma consulta; podría usar esta clase.
 */
class SesionesUsuario
{
    /**
     * Cierra todas las sesiones del usuario excepto la indicada (la actual).
     * Llamar DESPUÉS de regenerar la sesión, con el ID nuevo.
     *
     * @return int Cuántas sesiones se cerraron.
     */
    public static function cerrarOtras(User $user, string $idSesionActual): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $idSesionActual)
            ->delete();
    }
}
