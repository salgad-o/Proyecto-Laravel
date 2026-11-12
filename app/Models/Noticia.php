<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Noticia extends Model
{
    use Auditable, HasFactory {
        Auditable::camposNoAuditables as camposNoAuditablesBase;
    }

    protected $table = 'noticias';

    /**
     * Solo los campos de texto que puede enviar el formulario. La portada y
     * published_at se asignan de forma explícita en el controlador: el archivo no
     * debe pasar nunca por asignación masiva.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'excerpt',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    /**
     * URL de la portada vigente, servida por /portadas/{archivo}. Nunca se usa
     * Storage::url(): el disco "local" es privado y no tiene enlace público.
     */
    protected function coverImageUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->cover_image
            ? route('portadas.mostrar', ['archivo' => $this->cover_image])
            : null);
    }

    /**
     * La ruta del archivo no se guarda en la auditoría. Los cambios de portada se
     * registran a mano como "cambiar_portada" (con portada / sin portada), igual
     * que la foto de perfil.
     */
    public function camposNoAuditables(): array
    {
        return [...$this->camposNoAuditablesBase(), 'cover_image'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Comentario, $this> */
    public function comentarios(): HasMany
    {
        return $this->hasMany(Comentario::class);
    }

    /**
     * Único lugar con la regla de lo que ve el público: nunca borradores.
     */
    public function scopePublicadas($query)
    {
        return $query->where('status', 'publicada');
    }

    /**
     * Búsqueda pública por título o resumen. El término se trata como texto literal.
     */
    public function scopeBuscar($query, ?string $termino)
    {
        if ($termino === null) {
            return $query;
        }

        // "!" como carácter de escape: funciona igual en MySQL/MariaDB y SQLite.
        // Se escapan "!", "%" y "_" para que se busquen como texto literal.
        $escapado = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $termino);

        // El closure es obligatorio: sin él, el orWhere se saltaría el filtro
        // status='publicada' y la búsqueda mostraría borradores.
        return $query->where(function ($q) use ($escapado) {
            $q->whereRaw("title LIKE ? ESCAPE '!'", ["%{$escapado}%"])
                ->orWhereRaw("excerpt LIKE ? ESCAPE '!'", ["%{$escapado}%"]);
        });
    }

    /**
     * Minutos de lectura, a 200 palabras por minuto. Se separa con preg_split
     * y no con str_word_count para que las tildes no corten las palabras.
     */
    public function minutosLectura(): int
    {
        $palabras = preg_split('/\s+/u', trim((string) $this->body), -1, PREG_SPLIT_NO_EMPTY);

        return max(1, (int) ceil(count($palabras) / 200));
    }

    /**
     * Pasar de borrador a publicada se audita como "publicar"; el resto, como "editar".
     */
    public function accionDeActualizacion(array $cambios): string
    {
        return ($cambios['status'] ?? null) === 'publicada' ? 'publicar' : 'editar';
    }
}
