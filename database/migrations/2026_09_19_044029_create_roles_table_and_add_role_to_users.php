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
        // 1. Tabla de roles.
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();   // identificador interno: admin, editor, lector
            $table->string('label', 50);            // nombre para mostrar
            $table->timestamps();
        });

        // 2. Roles base del sistema.
        $now = now();

        DB::table('roles')->insert([
            ['name' => 'admin',  'label' => 'Administrador', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'editor', 'label' => 'Editor',        'created_at' => $now, 'updated_at' => $now],
            ['name' => 'lector', 'label' => 'Lector',        'created_at' => $now, 'updated_at' => $now],
        ]);

        $lectorId = DB::table('roles')->where('name', 'lector')->value('id');

        // 3. Cada usuario tendrá un rol; por defecto, "lector" (el de menos privilegios).
        Schema::table('users', function (Blueprint $table) use ($lectorId) {
            $table->unsignedBigInteger('role_id')->default($lectorId)->after('password');
            $table->foreign('role_id')->references('id')->on('roles');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('roles');
    }
};