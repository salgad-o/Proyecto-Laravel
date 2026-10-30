<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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

    /**
     * Pasar de borrador a publicada se audita como "publicar"; el resto, como "editar".
     */
    public function accionDeActualizacion(array $cambios): string
    {
        return ($cambios['status'] ?? null) === 'publicada' ? 'publicar' : 'editar';
    }
}
