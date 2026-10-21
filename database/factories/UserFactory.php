<?php

namespace Database\Factories;

use App\Domain\Access\Actions\SyncAccessCatalog;
use App\Domain\Identity\Enums\PersonType;
use App\Domain\Identity\Enums\UserKind;
use App\Domain\Identity\Enums\UserStatus;
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
     * Default state: an active natural-person member.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => UserKind::Member,
            'person_type' => PersonType::Natural,
            'member_number' => 'CECT-T'.Str::upper(Str::random(8)),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+228'.fake()->numerify('9#######'),
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('Password123'),
            'remember_token' => Str::random(10),
        ];
    }

    public function legalPerson(): static
    {
        return $this->state(fn (array $attributes) => ['person_type' => PersonType::Legal]);
    }

    public function status(UserStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['status' => $status]);
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
            'status' => UserStatus::PendingEmailVerification,
        ]);
    }

    /**
     * An active internal account holding the given system or custom roles.
     */
    public function staff(string ...$roles): static
    {
        return $this->state(fn (array $attributes) => [
            'kind' => UserKind::Staff,
            'person_type' => null,
            'member_number' => null,
        ])->afterCreating(function (User $user) use ($roles): void {
            if ($roles !== []) {
                app(SyncAccessCatalog::class)->handle();
                // Dedicated KYC validator is now an approved system role.
                $user->syncRoles(array_values(array_unique($roles)));
            }
        });
    }
}
