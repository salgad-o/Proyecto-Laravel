<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable;

    /**
     * "name" no es asignable: es el identificador interno que usan las Policies
     * y el middleware, y no debe cambiarse desde un formulario.
     *
     * @var list<string>
     */
    protected $fillable = [
        'label',
        'description',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
