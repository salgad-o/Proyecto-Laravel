<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comentario extends Model
{
    use Auditable, HasFactory;

    protected $table = 'comentarios';

    // noticia_id y user_id NO son asignables: se fijan desde el controlador.
    protected $fillable = ['body'];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function noticia(): BelongsTo
    {
        return $this->belongsTo(Noticia::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
