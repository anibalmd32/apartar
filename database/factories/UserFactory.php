<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TeamInvitation;
use App\Models\User;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<User> */
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<model-property<User>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => OrganizationRole::Member,
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => OrganizationRole::Owner]);
    }

    public function invited(?TeamInvitation $invitation = null): static
    {
        return $this->state(fn (): array => [
            'password' => null,
            'email_verified_at' => null,
            'invited_at' => now(),
            'invitation_token_hash' => $invitation?->tokenHash,
            'invitation_expires_at' => $invitation?->expiresAt,
        ]);
    }

    public function withExpiredInvitation(TeamInvitation $invitation): static
    {
        return $this->invited($invitation)->state(fn (): array => [
            'invitation_expires_at' => now()->subHour(),
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['disabled_at' => now()]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
