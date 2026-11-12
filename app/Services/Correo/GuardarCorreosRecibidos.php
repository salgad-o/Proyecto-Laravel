<?php

namespace App\Services\Correo;

use App\Models\ReceivedEmail;
use App\Support\Correo\NormalizadorCorreo;
use App\Support\Correo\ResultadoLectura;
use App\Support\Correo\ResumenGuardado;

/**
 * Guarda en received_emails los correos leídos del buzón (NP-46 paso 6).
 *
 * Idempotente: el mismo correo leído dos veces (o por dos ejecuciones a la vez)
 * deja UNA sola fila, y una fila existente nunca se sobrescribe.
 */
class GuardarCorreosRecibidos
{
    public function guardar(ResultadoLectura $lectura): ResumenGuardado
    {
        $nuevos = 0;
        $existentes = 0;

        foreach ($lectura->correos as $correo) {
            $datos = NormalizadorCorreo::paraGuardar($correo);
            $clave = ['message_id' => $datos['message_id']];
            unset($datos['message_id']);

            // createOrFirst (y no firstOrCreate): intenta el INSERT primero y, si el
            // UNIQUE de message_id lo rechaza, devuelve la fila que ya existía.
            // firstOrCreate haría SELECT y luego INSERT: dos ejecuciones simultáneas
            // podrían pasar las dos el SELECT y la segunda fallaría al insertar.
            // Si la fila ya existe NO se actualiza nada (ni asunto, ni cuerpo, ni is_read).
            //
            // unguarded(): el modelo no admite asignación masiva; aquí sí, porque el
            // arreglo lo construye NormalizadorCorreo y nunca incluye is_read ni read_at.
            $email = ReceivedEmail::unguarded(fn () => ReceivedEmail::createOrFirst($clave, $datos));

            $email->wasRecentlyCreated ? $nuevos++ : $existentes++;
        }

        return new ResumenGuardado($nuevos, $existentes, $lectura->omitidos);
    }
}
