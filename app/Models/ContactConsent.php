<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Constancia de autorización de tratamiento de datos de un mensaje de contacto
 * (NP-46, deuda 12). Solo guarda la prueba, no el mensaje (ver la migración).
 */
class ContactConsent extends Model
{
    /**
     * Solo el correo es asignable. "privacy_accepted_at", "legal_version" y
     * "message_id" son la prueba de la autorización: las asigna ControllerContact
     * de forma explícita, igual que AuthController@register con el usuario. Así,
     * un valor enviado en el formulario (legal_version=9.9, etc.) nunca se guarda.
     *
     * @var list<string>
     */
    protected $fillable = ['email'];

    protected function casts(): array
    {
        return [
            'privacy_accepted_at' => 'datetime',
        ];
    }
}
