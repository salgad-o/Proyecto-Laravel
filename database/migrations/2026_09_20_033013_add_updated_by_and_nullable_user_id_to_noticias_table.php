<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 1) noticias.user_id pasa de CASCADE a SET NULL: si se elimina al autor,
     *    la noticia se conserva (y con ella la evidencia de quién la escribió).
     * 2) Se agrega noticias.updated_by: quién modificó la noticia por última vez.
     */
    public function up(): void
    {
        // Paso A: quitar la clave foránea vieja. Su nombre es "posts_user_id_foreign"
        // porque Schema::rename() renombró la tabla pero no la restricción.
        Schema::table('noticias', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        // Paso B: permitir NULL en user_id. Debe hacerse con la clave foránea
        // ya quitada y antes de crear ON DELETE SET NULL, que exige columna nullable.
        Schema::table('noticias', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        // Paso C: recrear la clave foránea con SET NULL y agregar updated_by.
        Schema::table('noticias', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Aviso: si ya existen noticias con user_id NULL (autor eliminado),
     * el rollback fallará al volver la columna a NOT NULL. Es intencional:
     * es mejor fallar que inventar un autor.
     */
    public function down(): void
    {
        Schema::table('noticias', function (Blueprint $table) {
            $table->dropForeign(['updated_by']);
            $table->dropColumn('updated_by');
            $table->dropForeign(['user_id']);
        });

        Schema::table('noticias', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('noticias', function (Blueprint $table) {
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->cascadeOnDelete();
        });
    }
};