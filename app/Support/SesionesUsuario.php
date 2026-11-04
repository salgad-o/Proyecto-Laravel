<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operaciones sobre las sesiones guardadas de un usuario (NP-36).
 *
 * IMPORTANTE: depende de SESSION_DRIVER=database (el valor de .env.example).
 * Con otro driver (file, cookie, array, redis...) no hay filas en la tabla de
 * sesiones: listar() devuelve una colección vacía y cerrarOtras() devuelve 0.
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
     * ¿Las sesiones se guardan en la base de datos (y por tanto se pueden listar y cerrar)?
     */
    public static function disponible(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Sesiones abiertas del usuario, de la más reciente a la más antigua.
     * Solo lee las filas de ESE usuario y nunca expone el id de sesión ni el payload.
     *
     * @return Collection<int, SesionActiva>
     */
    public static function listar(User $user, string $idSesionActual): Collection
    {
        if (! static::disponible()) {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(function (object $fila) use ($idSesionActual) {
                [$navegador, $sistema] = static::interpretarAgente($fila->user_agent);

                return new SesionActiva(
                    navegador: $navegador,
                    sistema: $sistema,
                    ip: $fila->ip_address,
                    ultimaActividad: Carbon::createFromTimestamp((int) $fila->last_activity, config('app.timezone')),
                    esActual: hash_equals($idSesionActual, (string) $fila->id),
                );
            })
            ->values();
    }

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

    /**
     * Interpretación simple del user_agent (sin paquetes): [navegador, sistema].
     * El sistema es null si el user_agent está vacío. Solo devuelve textos fijos:
     * nada del user_agent original llega a la vista.
     *
     * El orden importa: Edge y Opera también dicen "Chrome" (y "Safari"), y
     * Chrome dice "Safari"; iOS y Android van antes que macOS y Linux.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function interpretarAgente(?string $agente): array
    {
        $agente = trim((string) $agente);

        if ($agente === '') {
            return ['Navegador desconocido', null];
        }

        $navegador = match (true) {
            static::contiene($agente, ['Edg/', 'Edge/', 'EdgA/', 'EdgiOS/']) => 'Edge',
            static::contiene($agente, ['OPR/', 'Opera', 'OPiOS/']) => 'Opera',
            static::contiene($agente, ['Firefox/', 'FxiOS/']) => 'Firefox',
            static::contiene($agente, ['Chrome/', 'CriOS/', 'Chromium/']) => 'Chrome',
            static::contiene($agente, ['Safari/']) => 'Safari',
            default => 'Otro navegador',
        };

        $sistema = match (true) {
            static::contiene($agente, ['iPhone', 'iPad', 'iPod']) => 'iOS',
            static::contiene($agente, ['Android']) => 'Android',
            static::contiene($agente, ['Windows']) => 'Windows',
            static::contiene($agente, ['Macintosh', 'Mac OS X']) => 'macOS',
            static::contiene($agente, ['Linux', 'X11', 'CrOS']) => 'Linux',
            default => 'Otro sistema',
        };

        return [$navegador, $sistema];
    }

    /**
     * @param  list<string>  $marcas
     */
    private static function contiene(string $agente, array $marcas): bool
    {
        foreach ($marcas as $marca) {
            if (str_contains($agente, $marca)) {
                return true;
            }
        }

        return false;
    }
}
