<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ResetPlatformOperatorPasswordActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_resetting_a_password_forces_a_change_and_shows_the_new_one_once(): void
    {
        $other = $this->operator(['must_change_password' => false]);
        $this->asOperator();

        $this->post('/plataforma/operadores/'.$other->getKey().'/contrasena')->assertRedirect();

        $this->assertTrue($other->refresh()->must_change_password);
        $this->get('/plataforma/ajustes')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('temporaryPassword.password', fn (string $password): bool => Hash::check($password, $other->password)));
    }

    public function test_an_operator_cannot_reset_their_own_password_from_the_list(): void
    {
        $me = $this->asOperator();

        $this->post('/plataforma/operadores/'.$me->getKey().'/contrasena')->assertSessionHasErrors('operator');
    }
}
