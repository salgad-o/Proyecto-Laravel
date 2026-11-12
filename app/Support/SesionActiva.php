<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Una sesión abierta del usuario, lista para mostrar (NP-36 paso 4).
 *
 * A propósito NO guarda el id de sesión ni el payload: con el id de otra
 * sesión alguien podría suplantarla, y el payload tiene datos internos.
 * Tampoco guarda el user_agent crudo: solo los textos cortos ya interpretados.
 */
final class SesionActiva
{
    public function __construct(
        public readonly string $navegador,
        public readonly ?string $sistema,
        public readonly ?string $ip,
        public readonly Carbon $ultimaActividad,
        public readonly bool $esActual,
    ) {}

    /**
     * Texto corto del dispositivo, por ejemplo "Chrome en Windows".
     */
    public function descripcion(): string
    {
        return $this->sistema === null ? $this->navegador : "{$this->navegador} en {$this->sistema}";
    }
}
