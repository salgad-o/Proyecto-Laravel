<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NP-46 paso 3 (deuda 12): constancia de que el visitante autorizó el tratamiento
 * de sus datos al escribir por el formulario de contacto (Ley 1581 de 2012).
 *
 * Minimización de datos (decisión D1): se guarda SOLO la constancia. Sin nombre,
 * sin el texto del mensaje y sin IP. El mensaje viaja únicamente en el correo.
 *
 * message_id es el Message-ID del correo enviado: une la constancia con el correo
 * que llegó al buzón sin tener que guardar su contenido aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_consents', function (Blueprint $table) {
            $table->id();
            $table->string('email', 150);
            $table->timestamp('privacy_accepted_at');
            $table->string('legal_version', 20);
            $table->string('message_id', 255)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_consents');
    }
};
