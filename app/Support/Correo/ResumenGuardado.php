<?php

namespace App\Support\Correo;

/**
 * Resultado de guardar una lectura del buzón (NP-46 paso 6).
 */
final readonly class ResumenGuardado
{
    public function __construct(
        public int $nuevos,
        public int $existentes,
        public int $omitidos,
    ) {}
}
