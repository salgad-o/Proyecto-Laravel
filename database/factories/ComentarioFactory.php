<?php

namespace Database\Factories;

use App\Models\Comentario;
use App\Models\Noticia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comentario>
 */
class ComentarioFactory extends Factory
{
    protected $model = Comentario::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'noticia_id' => Noticia::factory(),
            'user_id' => User::factory()->lector(),
            'body' => fake()->sentence(15),
        ];
    }
}
