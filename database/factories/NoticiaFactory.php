<?php

namespace Database\Factories;

use App\Models\Noticia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Noticia>
 */
class NoticiaFactory extends Factory
{
    protected $model = Noticia::class;

    /**
     * Por defecto: una noticia publicada cuyo autor es un editor.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->editor(),
            'title' => fake()->sentence(6),
            'excerpt' => fake()->sentence(12),
            'body' => fake()->paragraphs(3, true),
            'status' => 'publicada',
            'published_at' => now(),
        ];
    }

    public function borrador(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'borrador',
            'published_at' => null,
        ]);
    }
}
