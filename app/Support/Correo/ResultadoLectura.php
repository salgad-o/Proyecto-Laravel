<?php

namespace App\Support\Correo;

/**
 * Lo que devuelve una lectura del buzón (NP-46 paso 6): los correos que se
 * pudieron leer y cuántos se omitieron (soft_fail del paquete o error al
 * convertirlos). Antes los omitidos desaparecían sin aviso (riesgo 3 del paso 5).
 */
final readonly class ResultadoLectura
{
    /**
     * @param  list<CorreoEntrante>  $correos
     */
    public function __construct(
        public array $correos,
        public int $omitidos = 0,
    ) {}
}
