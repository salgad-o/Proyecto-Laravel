<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;
use LogicException;

class AuditLog extends Model
{
    /** Los registros no se modifican, así que no hay updated_at. */
    public const UPDATED_AT = null;

    /** Acción interna => texto para mostrar. */
    public const ACCIONES = [
        'crear' => 'Creó',
        'editar' => 'Editó',
        'publicar' => 'Publicó',
        'eliminar' => 'Eliminó',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Solo lectura: cualquier intento de modificar o borrar un registro desde el código falla.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Los registros de auditoría son de solo lectura.');
        });

        static::deleting(function () {
            throw new LogicException('Los registros de auditoría no se pueden eliminar.');
        });
    }

    /**
     * Guarda un registro con el usuario autenticado, la IP y el navegador de la petición actual.
     */
    public static function registrar(string $accion, Model $modelo, ?array $antes, ?array $despues): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'action' => $accion,
            'auditable_type' => $modelo->getMorphClass(),
            'auditable_id' => $modelo->getKey(),
            'old_values' => $antes,
            'new_values' => $despues,
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 250, ''),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function etiquetaAccion(): string
    {
        return self::ACCIONES[$this->action] ?? $this->action;
    }

    /** Nombre corto del tipo de elemento, ej. "Noticia". */
    public function tipoElemento(): string
    {
        return class_basename($this->auditable_type);
    }

    /**
     * Campos que cambiaron, con su valor anterior y nuevo.
     *
     * @return array<string, array{antes: mixed, despues: mixed}>
     */
    public function cambios(): array
    {
        $antes = $this->old_values ?? [];
        $despues = $this->new_values ?? [];
        $resultado = [];

        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $resultado[$campo] = [
                'antes' => $antes[$campo] ?? null,
                'despues' => $despues[$campo] ?? null,
            ];
        }

        return $resultado;
    }
}
