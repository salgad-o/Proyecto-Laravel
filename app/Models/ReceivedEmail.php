<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Correo recibido por IMAP (NP-46 paso 6).
 *
 * Su contenido es ENTRADA NO CONFIABLE: texto plano que se escapa con {{ }} al
 * mostrarlo. Nunca se renderiza como HTML.
 */
class ReceivedEmail extends Model
{
    /**
     * Nada es asignable en masa. Las filas solo las crea GuardarCorreosRecibidos con
     * un arreglo que construye él mismo (NormalizadorCorreo), y nunca incluye
     * is_read ni read_at: esos los cambiará solo el panel (paso 7), uno a uno.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'read_at' => 'datetime',
            'has_attachments' => 'boolean',
            'is_read' => 'boolean',
        ];
    }
}
