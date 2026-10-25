<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformUser;
use App\Models\User;
use Database\Factories\PlatformUserFactory;
use Database\Seeders\PlatformAdminSeeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlatformAuthTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    private const ENV = [
        'PLATFORM_ADMIN_NAME' => 'Ana Operadora',
        'PLATFORM_ADMIN_EMAIL' => 'Ana@Apartar.test',
        'PLATFORM_ADMIN_PASSWORD' => 'Operadora-Segura-2026',
    ];

    public function test_the_seeder_creates_the_first_operator_once(): void
    {
        $this->seedAdmin(self::ENV);
        $created = PlatformUser::query()->sole();
        $created->forceFill(['must_change_password' => false])->save();

        $this->seedAdmin([...self::ENV, 'PLATFORM_ADMIN_PASSWORD' => 'Otra-Contrasena-2027']);

        $this->assertSame(1, PlatformUser::query()->count());
        $this->assertSame('ana@apartar.test', $created->refresh()->email);
        $this->assertFalse($created->must_change_password);
        $this->assertTrue(Auth::guard('platform')->validate(['email' => 'ana@apartar.test', 'password' => self::ENV['PLATFORM_ADMIN_PASSWORD']]));
    }

    public function test_missing_variables_abort_listing_them(): void
    {
        try {
            $this->seedAdmin(['PLATFORM_ADMIN_NAME' => 'Ana']);
            $this->fail('Tenía que abortar.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('PLATFORM_ADMIN_EMAIL, PLATFORM_ADMIN_PASSWORD', $exception->getMessage());
        }

        $this->assertSame(0, PlatformUser::query()->count());
    }

    public function test_a_weak_password_aborts(): void
    {
        $this->expectException(RuntimeException::class);

        $this->seedAdmin([...self::ENV, 'PLATFORM_ADMIN_PASSWORD' => 'corta']);
    }

    public function test_organization_credentials_do_not_open_the_platform(): void
    {
        [, $owner] = $this->businessWithOwner();
        $this->operator(['email' => $owner->email]);

        $this->post('/plataforma/login', ['email' => $owner->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest('platform');
        $this->assertSame(1, User::query()->withoutTenantScope()->where('email', $owner->email)->count());
    }

    public function test_login_then_forced_password_change(): void
    {
        $operator = PlatformUser::factory()->mustChangePassword()->create();

        $this->post('/plataforma/login', ['email' => $operator->email, 'password' => PlatformUserFactory::PASSWORD])->assertRedirect('/plataforma');
        $this->assertAuthenticatedAs($operator, 'platform');

        $this->get('/plataforma')->assertRedirect('/plataforma/contrasena');
        $this->put('/plataforma/contrasena', ['password' => 'Nueva-Clave-Segura-2026', 'password_confirmation' => 'Nueva-Clave-Segura-2026'])->assertRedirect('/plataforma');

        $this->assertFalse($operator->refresh()->must_change_password);
        $this->get('/plataforma')->assertOk();
    }

    public function test_a_disabled_operator_loses_the_session(): void
    {
        $operator = $this->asOperator();
        $this->get('/plataforma')->assertOk();

        $operator->forceFill(['disabled_at' => now()])->save();

        $this->get('/plataforma')->assertRedirect('/plataforma/login');
        $this->assertGuest('platform');
    }

    public function test_the_sixth_attempt_is_throttled(): void
    {
        $operator = $this->operator();

        foreach (range(1, 5) as $_) {
            $this->post('/plataforma/login', ['email' => $operator->email, 'password' => 'mala'])->assertSessionHasErrors('email');
        }

        $this->post('/plataforma/login', ['email' => $operator->email, 'password' => PlatformUserFactory::PASSWORD])->assertStatus(429);
    }

    public function test_guests_go_to_the_platform_login_and_sessions_are_independent(): void
    {
        $this->get('/plataforma/organizaciones')->assertRedirect('/plataforma/login');

        $this->asOperator();

        $this->get('/backoffice')->assertRedirect();
        $this->assertGuest('web');
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function seedAdmin(array $environment): void
    {
        (new PlatformAdminSeeder)->run($environment);
    }
}
