<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Usuarios del equipo, para los tests y para `DevSeeder`.
 *
 * **No declara `organization_id`.** La organización la resuelve
 * `CreatesWithinTenant` al persistir: la activa si la hay, y si no, una nueva.
 * Gracias a eso, un test que solo necesita "un usuario" sigue escribiendo
 * `User::factory()->create()` sin saber nada de tenancy —que es lo que
 * mantiene en pie el andamiaje heredado del starter kit— y un test que sí
 * sabe usa `withinOrganization()` y obtiene el usuario donde corresponde.
 *
 * El rol por defecto es **`member`**, el de menos capacidades: un test que
 * necesite un `owner` tiene que pedirlo, y así ninguno pasa por accidente
 * ejerciendo permisos que no había declarado (`REQ-ORG-003` RB-1).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<User> */
    protected $model = User::class;

    /**
     * Contraseña compartida por todos los usuarios de un mismo proceso: se
     * cifra una sola vez y ahorra un bcrypt por usuario sembrado.
     */
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
            'role' => 'member',
            'must_change_password' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Dueño de la organización: suscripción, facturación, equipo y ajustes.
     */
    public function owner(): static
    {
        return $this->state(fn (): array => ['role' => 'owner']);
    }

    /**
     * Usuario con la invitación pendiente: existe, pero sin contraseña
     * utilizable, y no puede entrar hasta establecerla (`REQ-ORG-004` RB-3).
     */
    public function invited(): static
    {
        return $this->state(fn (): array => [
            'password' => null,
            'email_verified_at' => null,
            'invited_at' => now(),
        ]);
    }

    /**
     * Usuario desactivado: pierde la sesión en su siguiente petición.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['disabled_at' => now()]);
    }

    /**
     * Usuario obligado a rotar su contraseña en el próximo acceso.
     */
    public function mustChangePassword(): static
    {
        return $this->state(fn (): array => ['must_change_password' => true]);
    }

    /**
     * Usuario con el correo sin verificar.
     */
    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }
}
