<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     * Sin role_id: la base de datos asigna "lector" por defecto.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    public function admin(): static
    {
        return $this->conRol('admin');
    }

    public function editor(): static
    {
        return $this->conRol('editor');
    }

    public function lector(): static
    {
        return $this->conRol('lector');
    }

    /**
     * Asigna un rol por su nombre interno. Solo se usa en pruebas y seeders:
     * en la aplicación, role_id sigue fuera de $fillable.
     */
    protected function conRol(string $rol): static
    {
        return $this->state(fn (array $attributes) => [
            'role_id' => Role::where('name', $rol)->value('id'),
        ]);
    }
}
