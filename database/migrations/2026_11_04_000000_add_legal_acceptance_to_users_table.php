<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NP-43 paso 3: prueba de que el usuario aceptó los Términos de uso y autorizó
 * el tratamiento de datos (Ley 1581 de 2012) al registrarse.
 *
 * Las columnas son NULLABLE a propósito: los usuarios creados antes de NP-43
 * nunca aceptaron nada y no hay que inventarles una fecha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // after() lo respeta MySQL; SQLite lo ignora y agrega las columnas al final.
            $table->timestamp('terms_accepted_at')->nullable()->after('is_active');
            $table->timestamp('privacy_accepted_at')->nullable()->after('terms_accepted_at');
            $table->string('legal_version', 20)->nullable()->after('privacy_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['terms_accepted_at', 'privacy_accepted_at', 'legal_version']);
        });
    }
};
