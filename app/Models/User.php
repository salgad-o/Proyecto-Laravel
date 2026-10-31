<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use Auditable {
        accionDeActualizacion as accionDeActualizacionBase;
        camposNoAuditables as camposNoAuditablesBase;
    }

    /**
     * Valor por defecto en memoria: sin esto, un usuario recién instanciado
     * tendría is_active = null hasta refrescarlo desde la base de datos.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The attributes that are mass assignable.
     * OJO: "role_id" e "is_active" NO están aquí a propósito (evita escalada de privilegios).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Campos del trait Auditable más la fecha de verificación del correo.
     */
    public function camposNoAuditables(): array
    {
        return [...$this->camposNoAuditablesBase(), 'email_verified_at'];
    }

    /**
     * Distingue los cambios de rol y de estado de una edición normal.
     */
    public function accionDeActualizacion(array $cambios): string
    {
        if (array_key_exists('role_id', $cambios)) {
            return 'cambiar_rol';
        }

        if (array_key_exists('is_active', $cambios)) {
            return $cambios['is_active'] ? 'activar' : 'desactivar';
        }

        return $this->accionDeActualizacionBase($cambios);
    }

    public function noticias()
    {
        return $this->hasMany(Noticia::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * ¿El usuario tiene alguno de estos roles? Ej: $user->hasRole('admin', 'editor')
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role?->name, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }
}