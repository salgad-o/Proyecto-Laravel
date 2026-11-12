<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comentarios', function (Blueprint $table) {
            $table->id();
            // Si se elimina la noticia o el usuario, sus comentarios se eliminan con ellos.
            $table->foreignId('noticia_id')->constrained('noticias')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('edited_at')->nullable(); // marca "editado"
            $table->timestamps();

            $table->index(['noticia_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comentarios');
    }
};
