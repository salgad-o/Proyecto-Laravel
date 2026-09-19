<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Renombrar la tabla: conserva todas las filas existentes.
        Schema::rename('posts', 'noticias');

        // 2. Agregar las columnas nuevas de una noticia.
        Schema::table('noticias', function (Blueprint $table) {
            $table->string('excerpt', 300)->nullable()->after('title');
            $table->string('status', 20)->default('borrador')->after('body');
            $table->timestamp('published_at')->nullable()->after('status');
        });

        // 3. Las publicaciones que ya existían pasan a "publicada",
        //    con published_at igual a su fecha de creación.
        DB::table('noticias')->update([
            'status'       => 'publicada',
            'published_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('noticias', function (Blueprint $table) {
            $table->dropColumn(['excerpt', 'status', 'published_at']);
        });

        Schema::rename('noticias', 'posts');
    }
};