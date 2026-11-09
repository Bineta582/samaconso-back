<?php

namespace Database\Factories;

use App\Enums\Profil;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->lastName(),
            'prenom' => fake()->firstName(),
            'email' => fake()->unique()->safeEmail(),
            'telephone' => '+221770000000',
            'password' => 'Password123',
            'profil' => Profil::Abonne,
            'statut' => 'actif',
        ];
    }

    public function administrateur(): static
    {
        return $this->state(fn () => ['profil' => Profil::Administrateur]);
    }
}
