<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `REQ-ORG-003` RB-5 · los operadores de la plataforma no son equipo de nadie.
 *
 * Son otra tabla, otro guard y otro modelo (`D-5`), y la separación es la
 * razón por la que un `platform_user` no tiene rol de organización: no hay
 * ninguna en la que tenerlo. Si algún día `UserPolicy` o `EnsureIsOwner`
 * necesitaran contemplarlos, sería la señal de que las dos identidades se han
 * mezclado —y la mezcla, en un sistema multi-tenant, acaba en un operador con
 * sesión de backoffice de un negocio concreto—.
 *
 * Se comprueba por los dos lados: que una consulta de equipo no los alcanza, y
 * que el código del equipo no los nombra.
 */
final class TeamExcludesPlatformUsersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Archivos que deciden permisos de equipo.
     *
     * @return array<string, array{0: string}>
     */
    public static function teamPermissionFiles(): array
    {
        $files = [
            'app/Policies/UserPolicy.php',
            'app/Policies/OrganizationPolicy.php',
            'app/Policies/SubscriptionPolicy.php',
            'app/Http/Middleware/EnsureIsOwner.php',
            'app/Domain/Tenancy/OrganizationRole.php',
            'app/Domain/Tenancy/OrganizationCapability.php',
            'app/Domain/Tenancy/LastOwnerGuard.php',
        ];

        return array_combine($files, array_map(fn (string $file): array => [$file], $files));
    }

    public function test_a_team_query_does_not_reach_platform_operators(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('operadora@plataforma.test');

        $member = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->actingAsOrganization($organization);

        $team = User::query()->get();

        $this->assertCount(1, $team);
        $this->assertTrue($team->first()?->is($member));
    }

    /**
     * Ni siquiera buscándolo por su correo: el equipo vive en `users` y la
     * plataforma en `platform_users`, sin puente entre las dos.
     */
    public function test_a_platform_operator_is_not_found_by_email_in_the_team(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('buscada@plataforma.test');

        $this->actingAsOrganization($organization);

        $this->assertFalse(User::query()->where('email', 'buscada@plataforma.test')->exists());
    }

    /**
     * Y el correo de un operador no colisiona con el de un miembro de equipo:
     * la unicidad global de `users.email` (`REQ-ORG-002` RB-3) es de `users`,
     * no de las dos tablas juntas, porque son identidades distintas de la
     * misma persona.
     */
    public function test_the_same_address_may_exist_in_both_tables(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $this->seedPlatformUser('doble@ejemplo.test');

        $user = $this->withinOrganization(
            $organization,
            fn (): User => User::factory()->create(['email' => 'doble@ejemplo.test']),
        );

        $this->assertTrue($user->exists);
        $this->assertSame(1, DB::table('platform_users')->where('email', 'doble@ejemplo.test')->count());
    }

    /**
     * El otro lado: el código que decide permisos de equipo no nombra a la
     * plataforma en ninguna parte.
     *
     * Es una comprobación de arquitectura y hoy pasa sobre un conjunto sin
     * ninguna mención; el día que alguien resuelva un permiso de equipo
     * mirando a `platform_users`, falla sin que nadie tenga que acordarse.
     */
    #[DataProvider('teamPermissionFiles')]
    public function test_the_team_permission_code_never_names_the_platform(string $file): void
    {
        $code = $this->codeOf($file);

        $this->assertStringNotContainsString('platform_users', $code);
        $this->assertStringNotContainsString('PlatformUser', $code);
        $this->assertStringNotContainsString("guard('platform')", $code);
    }

    /**
     * Siembra un operador de plataforma a mano: `PlatformUser` llega con
     * `T-012` y lo que aquí importa es la fila, no el modelo.
     */
    private function seedPlatformUser(string $email): void
    {
        DB::table('platform_users')->insert([
            'name' => 'Operadora de plataforma',
            'email' => $email,
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Código de un archivo sin sus comentarios.
     *
     * Explicar por escrito **por qué** los operadores de plataforma no
     * aparecen aquí no es contemplarlos; contar el docblock como una mención
     * obligaría a borrar justo la explicación que hará falta dentro de seis
     * meses.
     */
    private function codeOf(string $file): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents(base_path($file))) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $token[1];
        }

        return $code;
    }
}
