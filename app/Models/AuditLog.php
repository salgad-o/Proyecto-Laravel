<?php

namespace App\Models;

use App\Support\SesionesUsuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;

/**
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 */
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
        'cambiar_rol' => 'Cambió rol',
        'activar' => 'Activó usuario',
        'desactivar' => 'Desactivó usuario',
        'cambiar_password' => 'Cambió contraseña',
        'cerrar_sesiones' => 'Cerró otras sesiones',
        'cambiar_avatar' => 'Cambió foto de perfil',
        'cambiar_portada' => 'Cambió portada',
        // NP-46 paso 7: marcar un correo recibido como leído o no leído (solo is_read).
        'marcar_correo' => 'Marcó correo',
    ];

    /**
     * Nivel de importancia de cada acción (NP-20 paso 5). Se calcula en código, no se
     * guarda: reclasificar una acción no exige migrar ni reescribir registros.
     * Alta: seguridad de cuentas y borrados. Media: cambios de contenido o perfil.
     * Baja: altas. Una acción que no esté aquí cuenta como baja (ver importancia()).
     */
    public const IMPORTANCIA = [
        'cambiar_rol' => 'alta',
        'desactivar' => 'alta',
        'activar' => 'alta',
        'cambiar_password' => 'alta',
        'cerrar_sesiones' => 'alta',
        'eliminar' => 'alta',
        'publicar' => 'media',
        'editar' => 'media',
        'cambiar_avatar' => 'media',
        'crear' => 'baja',
        'cambiar_portada' => 'baja',
        'marcar_correo' => 'baja',
    ];

    /**
     * Actividad PROPIA visible para quien no tiene AuditLogPolicy::viewAny (NP-21,
     * ajuste 2d, mínimo privilegio): tipo de elemento => acciones. Va por PARES
     * porque crear/editar/eliminar se registran igual sobre noticias, comentarios,
     * usuarios y roles, y un exadmin no debe seguir viendo, por ejemplo, que editó
     * un rol. Queda fuera todo lo que solo hace la administración: cambiar_rol,
     * activar y desactivar (incluida la autodesactivación) y cualquier acción sobre
     * roles. Las de User solo cuentan si el elemento es el propio usuario (su perfil).
     *
     * @var array<class-string, list<string>>
     */
    public const ACCIONES_PROPIAS = [
        Noticia::class => ['crear', 'editar', 'publicar', 'eliminar'],
        Comentario::class => ['crear', 'editar', 'eliminar'],
        User::class => ['editar', 'cambiar_avatar', 'cambiar_password', 'cerrar_sesiones'],
    ];

    /** Nivel => texto para mostrar, de mayor a menor. */
    public const NIVELES = [
        'alta' => 'Alta',
        'media' => 'Media',
        'baja' => 'Baja',
    ];

    /** Acciones que muestran "antes → después" en la vista. */
    public const ACCIONES_CON_VALOR_ANTERIOR = [
        'editar',
        'publicar',
        'cambiar_rol',
        'activar',
        'desactivar',
        'cambiar_avatar',
        'cambiar_portada',
        'marcar_correo',
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

    /**
     * Registros que $usuario puede ver como "su actividad" sin viewAny: los que hizo
     * él (user_id) y que están en ACCIONES_PROPIAS; los de tipo User, además, solo si
     * el elemento es él mismo (nunca otra cuenta, aunque la gestionara cuando era admin).
     */
    public function scopeVisiblesComoPropias(Builder $consulta, User $usuario): Builder
    {
        return $consulta
            ->where('user_id', $usuario->id)
            ->where(function (Builder $permitidas) use ($usuario) {
                foreach (self::ACCIONES_PROPIAS as $tipo => $acciones) {
                    $permitidas->orWhere(function (Builder $par) use ($tipo, $acciones, $usuario) {
                        $par->where('auditable_type', $tipo)->whereIn('action', $acciones);

                        if ($tipo === User::class) {
                            $par->where('auditable_id', $usuario->id);
                        }
                    });
                }
            });
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

    public function muestraValorAnterior(): bool
    {
        return in_array($this->action, self::ACCIONES_CON_VALOR_ANTERIOR, true);
    }

    /**
     * Texto para mostrar un valor auditado: nombre del rol en vez de su id y
     * "Activo"/"Inactivo" en vez de 1/0. $roles es el mapa id => nombre del rol.
     */
    public function valorLegible(string $campo, mixed $valor, Collection $roles): string
    {
        if ($valor === null) {
            return '(vacío)';
        }

        return match ($campo) {
            'role_id' => $roles->get((int) $valor, "rol #{$valor}"),
            'is_active' => $valor ? 'Activo' : 'Inactivo',
            'is_read' => $valor ? 'Leído' : 'No leído',
            default => (string) $valor,
        };
    }

    /** 'alta', 'media' o 'baja'. Una acción sin clasificar es 'baja': nunca rompe la vista. */
    public function importancia(): string
    {
        return self::IMPORTANCIA[$this->action] ?? 'baja';
    }

    public function etiquetaImportancia(): string
    {
        return self::NIVELES[$this->importancia()];
    }

    /**
     * Acciones clasificadas en un nivel, para filtrar en la consulta.
     *
     * @return list<string>
     */
    public static function accionesDeImportancia(string $nivel): array
    {
        return array_keys(array_filter(self::IMPORTANCIA, fn (string $valor) => $valor === $nivel));
    }

    /**
     * Nombre del tipo de elemento en español, ej. "Usuario". Un tipo sin traducir
     * muestra el nombre de la clase, como antes.
     */
    public function tipoElemento(): string
    {
        return match ($this->auditable_type) {
            Noticia::class => 'Noticia',
            Comentario::class => 'Comentario',
            User::class => 'Usuario',
            Role::class => 'Rol',
            ReceivedEmail::class => 'Correo recibido',
            default => class_basename($this->auditable_type),
        };
    }

    /**
     * Navegador del registro para la auditoría: "Chrome en Windows", solo "Chrome" si
     * el sistema no se reconoce, o "Navegador no reconocido". El user_agent crudo no se
     * muestra. Reutiliza SesionesUsuario::interpretarAgente sin cambiarlo (lo usa
     * también la pestaña Sesiones): aquí solo se traducen sus valores "Otro…".
     */
    public function navegadorLegible(): string
    {
        [$navegador, $sistema] = SesionesUsuario::interpretarAgente($this->user_agent);

        if (in_array($navegador, ['Otro navegador', 'Navegador desconocido'], true)) {
            return 'Navegador no reconocido';
        }

        if ($sistema === null || $sistema === 'Otro sistema') {
            return $navegador;
        }

        return "{$navegador} en {$sistema}";
    }

    /**
     * Texto cuando un registro no guarda valores (por ejemplo, cerrar sesiones).
     */
    public function textoSinValores(): string
    {
        return $this->action === 'cambiar_password'
            ? 'Por seguridad, la contraseña nunca se registra.'
            : 'Sin datos adicionales.';
    }

    /**
     * Cambios listos para mostrar, con la misma lógica que tenía la vista: el valor
     * anterior solo en ACCIONES_CON_VALOR_ANTERIOR y si se registró, "(vacío)" para
     * un null real, roles por nombre, Activo/Inactivo y valores largos recortados.
     *
     * @return list<array{campo: string, antes: ?string, despues: string, largo: bool}>
     */
    public function cambiosLegibles(Collection $roles, int $limite = 60): array
    {
        $lineas = [];

        foreach ($this->cambios() as $campo => $valor) {
            $antes = $this->muestraValorAnterior() && array_key_exists('antes', $valor)
                ? $this->valorLegible($campo, $valor['antes'], $roles)
                : null;
            $despues = $this->valorLegible($campo, array_key_exists('despues', $valor) ? $valor['despues'] : $valor['antes'], $roles);

            $lineas[] = [
                'campo' => (string) $campo,
                'antes' => $antes === null ? null : Str::limit($antes, $limite),
                'despues' => Str::limit($despues, $limite),
                'largo' => mb_strlen((string) $antes) > $limite || mb_strlen($despues) > $limite,
            ];
        }

        return $lineas;
    }

    /**
     * Campos que cambiaron, con su valor anterior y nuevo. Una clave solo existe
     * si ese valor se registró: así se distingue "no hay dato" de un null real.
     *
     * @return array<string, array{antes?: mixed, despues?: mixed}>
     */
    public function cambios(): array
    {
        $antes = $this->old_values ?? [];
        $despues = $this->new_values ?? [];
        $resultado = [];

        foreach (array_unique(array_merge(array_keys($antes), array_keys($despues))) as $campo) {
            $resultado[$campo] = [];

            if (array_key_exists($campo, $antes)) {
                $resultado[$campo]['antes'] = $antes[$campo];
            }

            if (array_key_exists($campo, $despues)) {
                $resultado[$campo]['despues'] = $despues[$campo];
            }
        }

        return $resultado;
    }
}
