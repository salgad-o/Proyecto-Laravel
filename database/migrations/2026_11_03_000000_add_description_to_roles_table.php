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
        Schema::table('roles', function (Blueprint $table) {
            $table->string('description', 255)->nullable();
        });

        $descripciones = [
            'admin' => 'Acceso completo: usuarios, roles, noticias, comentarios y auditoría.',
            'editor' => 'Crea y gestiona sus propias noticias; puede comentar las publicadas.',
            'lector' => 'Lee las noticias publicadas y comenta; gestiona sus propios comentarios.',
        ];

        foreach ($descripciones as $name => $description) {
            DB::table('roles')->where('name', $name)->update([
                'description' => $description,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
