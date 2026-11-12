<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NP-36 paso 2: biografía corta del usuario (solo la editan editores y administradores).
 * "bio" NO va en User::$fillable: PerfilController la asigna de forma explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('bio', 300)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('bio');
        });
    }
};
