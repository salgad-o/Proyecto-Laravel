<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\IndexCorreosRecibidosRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Filtro y página de la bandeja para el enlace "Volver" del detalle de un correo
 * (NP-46 paso 7). Si un valor no es válido se descarta EN SILENCIO: el enlace
 * vuelve a la bandeja sin él, en vez de mostrar un error en el detalle.
 */
trait VuelveALaBandeja
{
    /**
     * Parámetros válidos para route('correos.index', ...), sin los valores por defecto.
     *
     * @return array{estado?: string, page?: int}
     */
    public function parametrosDeVuelta(): array
    {
        $parametros = [];

        $estado = $this->query('estado', $this->input('estado'));
        if (Validator::make(['estado' => $estado], ['estado' => ['required', 'string', Rule::in(IndexCorreosRecibidosRequest::ESTADOS)]])->passes()
            && $estado !== 'todos') {
            $parametros['estado'] = $estado;
        }

        $pagina = $this->query('page', $this->input('page'));
        if (Validator::make(['page' => $pagina], ['page' => ['required', 'integer', 'min:2', 'max:100000']])->passes()) {
            $parametros['page'] = (int) $pagina;
        }

        return $parametros;
    }
}
