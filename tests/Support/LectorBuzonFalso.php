<?php

namespace Tests\Support;

use App\Contracts\LectorBuzon;
use App\Support\Correo\CorreoEntrante;
use App\Support\Correo\ResultadoLectura;
use Throwable;

/**
 * LectorBuzon falso para los tests (D8): devuelve correos fijos o lanza una
 * excepción, y anota cada llamada. Nunca se conecta a ningún servidor.
 */
class LectorBuzonFalso implements LectorBuzon
{
    /** @var list<array{0: int, 1: int}> */
    public array $llamadas = [];

    /**
     * @param  list<CorreoEntrante>  $correos
     */
    public function __construct(
        private readonly array $correos = [],
        private readonly ?Throwable $error = null,
        private readonly int $omitidos = 0,
    ) {}

    public function recientes(int $limite, int $dias): ResultadoLectura
    {
        $this->llamadas[] = [$limite, $dias];

        if ($this->error !== null) {
            throw $this->error;
        }

        return new ResultadoLectura($this->correos, $this->omitidos);
    }
}
