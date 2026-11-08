<?php

namespace App\Contracts;

use App\Support\Correo\CorreoEntrante;

/**
 * Lectura del buzón de correo entrante (NP-46 paso 5, decisión D8).
 *
 * El comando mail:receive depende de esta interfaz y no del paquete IMAP: en
 * producción se usa LectorBuzonWebklex y en los tests una implementación falsa,
 * así que los tests nunca se conectan a Gmail.
 */
interface LectorBuzon
{
    /**
     * Correos de la bandeja de entrada de los últimos $dias días, del más reciente
     * al más antiguo, como máximo $limite. No cambia ningún flag (D7).
     *
     * @return iterable<CorreoEntrante>
     */
    public function recientes(int $limite, int $dias): iterable;
}
