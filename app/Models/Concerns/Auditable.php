<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;

/**
 * Audita automáticamente la creación, edición y eliminación de un modelo.
 * Uso: agregar `use Auditable;` en el modelo (Noticia hoy; Comentario y User más adelante).
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function ($modelo) {
            AuditLog::registrar('crear', $modelo, null, $modelo->valoresAuditables($modelo->getAttributes()));
        });

        static::updated(function ($modelo) {
            $despues = $modelo->valoresAuditables($modelo->getChanges());

            if ($despues === []) {
                return; // solo cambiaron campos que no se auditan (ej. updated_at)
            }

            // En el evento "updated", getRawOriginal() todavía tiene los valores anteriores.
            $antes = array_intersect_key($modelo->valoresAuditables($modelo->getRawOriginal()), $despues);

            AuditLog::registrar($modelo->accionDeActualizacion($despues), $modelo, $antes, $despues);
        });

        static::deleted(function ($modelo) {
            AuditLog::registrar('eliminar', $modelo, $modelo->valoresAuditables($modelo->getAttributes()), null);
        });
    }

    /**
     * Campos que nunca se guardan en la auditoría (secretos o ruido).
     */
    public function camposNoAuditables(): array
    {
        return ['created_at', 'updated_at', 'password', 'remember_token'];
    }

    public function valoresAuditables(array $valores): array
    {
        return array_diff_key($valores, array_flip($this->camposNoAuditables()));
    }

    /**
     * Acción que se registra al actualizar. Cada modelo puede sobrescribirla.
     */
    public function accionDeActualizacion(array $cambios): string
    {
        return 'editar';
    }
}
