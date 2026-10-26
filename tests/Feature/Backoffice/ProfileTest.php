<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProfileTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private const EMAIL = 'mariela@ejemplo.test';

    private const PASSWORD = 'Cl4ve-Segura-Nueva';

    public function test_the_profile_shows_the_data_and_how_many_businesses_share_it(): void
    {
        [$first] = $this->twoMemberships();

        $this->actingAs($first)
            ->get('/backoffice/perfil')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/profile/Edit')
                ->where('profile.email', self::EMAIL)
                ->where('profile.businesses', 2));
    }

    public function test_keeping_the_same_email_saves_the_name_in_every_business(): void
    {
        [$first, $second] = $this->twoMemberships();

        $this->actingAs($first)
            ->patch('/backoffice/perfil', ['name' => 'Mariela Rojas', 'email' => self::EMAIL])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Datos guardados.');

        $this->assertSame('Mariela Rojas', $first->refresh()->name);
        $this->assertSame('Mariela Rojas', User::withoutTenantScope()->findOrFail($second->getKey())->name);
        $this->assertNotNull($first->email_verified_at);
    }

    public function test_a_new_email_moves_every_business_and_asks_to_confirm_it(): void
    {
        Notification::fake();
        [$first, $second] = $this->twoMemberships();

        $this->actingAs($first)
            ->patch('/backoffice/perfil', ['name' => 'Mariela', 'email' => 'Nuevo@Ejemplo.test'])
            ->assertSessionHasNoErrors();

        $first->refresh();
        $other = User::withoutTenantScope()->findOrFail($second->getKey());

        $this->assertSame('nuevo@ejemplo.test', $first->email);
        $this->assertSame('nuevo@ejemplo.test', $other->email);
        $this->assertNull($first->email_verified_at);
        $this->assertNull($other->email_verified_at);
        Notification::assertSentTo($first, VerifyEmail::class);
    }

    public function test_an_email_used_by_someone_else_is_refused(): void
    {
        [$first] = $this->twoMemberships();
        $stranger = $this->subscribe(Organization::factory()->create());
        $this->withinOrganization($stranger, fn () => User::factory()->owner()->create(['email' => 'otra@ejemplo.test']));
        $this->withoutOrganization();

        $this->actingAs($first)
            ->patch('/backoffice/perfil', ['name' => 'Mariela', 'email' => 'otra@ejemplo.test'])
            ->assertSessionHasErrors(['email' => 'Ese correo ya lo usa otra persona en Apartar.']);

        $this->assertSame(self::EMAIL, $first->refresh()->email);
    }

    public function test_the_password_changes_in_every_business_with_the_current_one(): void
    {
        [$first, $second] = $this->twoMemberships();

        $this->actingAs($first)
            ->put('/backoffice/perfil/contrasena', [
                'current_password' => 'password',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Contraseña cambiada.');

        $this->assertTrue(Hash::check(self::PASSWORD, $first->refresh()->password));
        $this->assertTrue(Hash::check(self::PASSWORD, User::withoutTenantScope()->findOrFail($second->getKey())->password));
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        [$first] = $this->twoMemberships();

        $this->actingAs($first)
            ->put('/backoffice/perfil/contrasena', [
                'current_password' => 'no-es-esta',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasErrors(['current_password' => 'La contraseña actual no es correcta.']);

        $this->assertTrue(Hash::check('password', $first->refresh()->password));
    }

    public function test_the_starter_kit_settings_no_longer_exist(): void
    {
        [$first] = $this->twoMemberships();

        $this->actingAs($first)->get('/settings/profile')->assertNotFound();
        $this->actingAs($first)->delete('/settings/profile')->assertNotFound();
    }

    /**
     * @return array{0: User, 1: User}
     */
    private function twoMemberships(): array
    {
        $one = $this->subscribe(Organization::factory()->create());
        $two = $this->subscribe(Organization::factory()->create());
        $first = $this->withinOrganization($one, fn (): User => User::factory()->owner()->create(['email' => self::EMAIL]));
        $second = $this->withinOrganization($two, fn (): User => User::factory()->create(['email' => self::EMAIL, 'role' => OrganizationRole::Member]));
        $this->withoutOrganization();

        return [$first, $second];
    }
}
