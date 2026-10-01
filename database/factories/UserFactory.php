<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Users\UserStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The hashes are deliberately weak: these accounts only ever exist inside
     * the isolated test database.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => UserStatus::Active,
            'last_login_at' => null,
            'password_changed_at' => now(),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * An account that is blocked from signing in.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => UserStatus::Inactive,
        ]);
    }

    /**
     * A known password, for tests that must authenticate.
     */
    public function withPassword(string $password): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => Hash::make($password),
        ]);
    }
}
