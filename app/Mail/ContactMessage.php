<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Mensaje del formulario de contacto hacia el buzón del proyecto (NP-46 paso 3).
 *
 * - Se envía en cola (ShouldQueue): la respuesta al visitante no espera al SMTP.
 * - Solo recibe escalares, nunca modelos: el job no depende de que exista una fila.
 * - From: el global de config('mail.from'). El correo del visitante va SOLO en
 *   Reply-To (si fuera el From, Gmail lo marcaría como suplantación y SPF/DMARC fallarían).
 * - Asunto fijo y solo texto plano (decisión D3): nada del visitante en el asunto
 *   ni en HTML. La vista de texto imprime sin escapar a propósito (ver su comentario).
 * - ShouldBeEncrypted: el job se guarda cifrado con APP_KEY en "jobs" (y en
 *   "failed_jobs" si falla). Nombre, correo y mensaje no quedan en claro en la BD
 *   (minimización de datos, decisión D1).
 */
class ContactMessage extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const ASUNTO = 'Nuevo mensaje de contacto - Nexus Plays';

    /**
     * Intentos del job antes de pasar a failed_jobs.
     */
    public int $tries = 3;

    /**
     * Espera entre reintentos (segundos): 1 min, luego 5 min.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300];

    /**
     * @param  string  $autorizadoEn  Fecha de la autorización en formato ISO 8601.
     * @param  string  $messageId  Message-ID sin los < >, igual al de la constancia.
     */
    public function __construct(
        public readonly string $nombre,
        public readonly string $correo,
        public readonly string $mensaje,
        public readonly string $autorizadoEn,
        public readonly string $legalVersion,
        public readonly string $messageId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->correo, $this->nombre)],
            subject: self::ASUNTO,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.contacto',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
        );
    }
}
