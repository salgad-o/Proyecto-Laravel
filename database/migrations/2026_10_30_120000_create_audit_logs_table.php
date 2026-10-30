<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro de auditoría: quién hizo qué, sobre qué elemento, cuándo y desde dónde.
     * Solo se inserta; nunca se actualiza ni se borra desde la aplicación.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Si el usuario se elimina, el registro se conserva (user_id queda en null).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 30);                 // crear, editar, publicar, eliminar
            $table->string('auditable_type');             // clase del elemento, ej. App\Models\Noticia
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();       // valores antes del cambio
            $table->json('new_values')->nullable();       // valores después del cambio
            $table->string('ip_address', 45)->nullable(); // 45 caracteres: cabe una IPv6
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
