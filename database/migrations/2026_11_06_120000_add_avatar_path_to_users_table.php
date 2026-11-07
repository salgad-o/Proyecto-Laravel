<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NP-39 paso 2: foto de perfil.
 *
 * Guarda SOLO el nombre del archivo (40 caracteres aleatorios + ".webp" = 45),
 * nunca una ruta: la carpeta "avatares/" la pone AvatarController. Así un valor
 * en la base de datos no puede apuntar a otro archivo del disco.
 *
 * "avatar_path" NO va en User::$fillable: AvatarController lo asigna de forma explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path', 64)->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
