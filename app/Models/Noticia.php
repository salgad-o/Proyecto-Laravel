<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Noticia extends Model
{
    use Auditable, HasFactory;

    protected $table = 'noticias';

    protected $fillable = ['title', 'excerpt', 'body', 'status'];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

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
