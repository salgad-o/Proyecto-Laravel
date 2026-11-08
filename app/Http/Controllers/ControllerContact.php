<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContactRequest;
use App\Mail\ContactMessage;
use App\Models\ContactConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ControllerContact extends Controller
{
    public function send(ContactRequest $request)
    {
        $datos = $request->validated();

        $buzon = config('mail.from.address');

        // Message-ID propio: el mismo valor queda en la constancia y en la cabecera
        // del correo, así se puede relacionar una con el otro sin guardar el mensaje.
        $messageId = Str::uuid().'@'.Str::after($buzon, '@');

        $autorizadoEn = now();

        // Constancia y correo en la misma transacción: con QUEUE_CONNECTION=database
        // el job se inserta en la tabla jobs dentro de ella. Si algo falla, no queda
        // una constancia sin su correo (ni un correo sin su constancia).
        DB::transaction(function () use ($datos, $buzon, $messageId, $autorizadoEn) {
            // Deuda 12 / Ley 1581: prueba de la autorización. Las columnas de la prueba
            // se asignan explícitamente (están fuera de $fillable): lo que el visitante
            // envíe como legal_version, privacy_accepted_at o message_id se ignora.
            $constancia = new ContactConsent(['email' => $datos['email']]);
            $constancia->privacy_accepted_at = $autorizadoEn;
            $constancia->legal_version = config('legal.version');
            $constancia->message_id = $messageId;
            $constancia->save();

            // To y From = buzón del proyecto. El visitante solo va en Reply-To.
            Mail::to($buzon)->queue(new ContactMessage(
                nombre: $datos['name'],
                correo: $datos['email'],
                mensaje: $datos['message'],
                autorizadoEn: $autorizadoEn->toIso8601String(),
                legalVersion: (string) config('legal.version'),
                messageId: $messageId,
            ));
        });

        // Ruta con nombre en vez de back(): no depende del Referer, que el cliente
        // puede manipular para provocar una redirección abierta.
        return redirect()->route('contact')->with('status', 'Recibimos tu mensaje. Te responderemos al correo que indicaste.');
    }
}
