<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NP-46 paso 6: correos recibidos por IMAP (php artisan mail:receive).
 *
 * Todo lo que hay aquí es ENTRADA NO CONFIABLE (lo escribió un remitente
 * cualquiera): se guarda como texto plano y se escapa al mostrarlo (paso 7).
 * No se guardan adjuntos ni sus nombres; solo si el correo tenía alguno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_emails', function (Blueprint $table) {
            $table->id();

            // UNIQUE: es lo que hace idempotente la importación (createOrFirst se apoya
            // en él). Ya normalizado por NormalizadorCorreo: ≤ 255, sin espacios ni controles.
            //
            // Colación binaria (utf8mb4_bin) en MySQL/MariaDB: los Message-ID distinguen
            // mayúsculas (RFC 5322). Con una colación _ci, "ABC@x" y "abc@x" chocarían en
            // el UNIQUE: un correo enviado con el mismo ID en otras mayúsculas ocuparía el
            // lugar del legítimo (o lo haría pasar por "ya guardado"). SQLite (tests) no
            // conoce esa colación, pero ya compara de forma binaria por defecto.
            $messageId = $table->string('message_id', 255);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $messageId->collation('utf8mb4_bin');
            }
            $messageId->unique();

            $table->string('from_email', 255);
            $table->string('from_name', 150)->nullable();
            $table->string('subject', 255)->nullable();

            // Solo texto plano, ya truncado a 100 000 caracteres por CorreoEntrante.
            $table->longText('body')->nullable();

            // En UTC (la zona horaria de la aplicación es UTC; ver NP-40).
            $table->timestamp('received_at')->nullable()->index();

            $table->boolean('has_attachments')->default(false);

            // Estado en el panel, independiente del flag \Seen de Gmail.
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_emails');
    }
};
