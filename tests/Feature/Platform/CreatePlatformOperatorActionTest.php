<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformActivity;
use App\Models\PlatformUser;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CreatePlatformOperatorActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_an_operator_creates_another_with_a_temporary_password_shown_once(): void
    {
        $this->asOperator();

        $this->post('/plataforma/operadores', ['name' => 'Luis Soporte', 'email' => 'Luis@Apartar.App'])->assertRedirect();

        $created = PlatformUser::query()->where('email', 'luis@apartar.app')->firstOrFail();
        $this->assertTrue($created->must_change_password);
        $this->assertTrue($created->isActive());

        $page = $this->get('/plataforma/ajustes');
        $page->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('temporaryPassword.email', 'luis@apartar.app')
            ->where('temporaryPassword.password', fn (string $password): bool => Hash::check($password, $created->password) && strlen($password) === 16));

        $this->get('/plataforma/ajustes')->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('temporaryPassword', null));
        $this->assertSame(1, PlatformActivity::query()->where('action', PlatformAudit::OPERATOR_CREATED)->count());
    }

    public function test_the_email_of_an_operator_is_unique(): void
    {
        $this->operator(['email' => 'rosa@apartar.app']);
        $this->asOperator();

        $this->post('/plataforma/operadores', ['name' => 'Otra Rosa', 'email' => 'ROSA@apartar.app'])->assertSessionHasErrors('email');
        $this->assertSame(1, PlatformUser::query()->where('email', 'rosa@apartar.app')->count());
    }
}
