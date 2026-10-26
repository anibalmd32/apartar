<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\SetPlatformOperatorAccessAction;
use App\Models\PlatformActivity;
use App\Support\Auditing\PlatformAudit;
use InvalidArgumentException;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SetPlatformOperatorAccessActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_a_disabled_operator_loses_access_and_can_be_reactivated(): void
    {
        $other = $this->operator();
        $me = $this->asOperator();

        $this->patch('/plataforma/operadores/'.$other->getKey().'/acceso', ['active' => false])->assertRedirect();
        $this->assertFalse($other->refresh()->isActive());

        $this->actingAs($other, 'platform');
        $this->get('/plataforma')->assertRedirect('/plataforma/login');

        $this->actingAs($me, 'platform');
        $this->patch('/plataforma/operadores/'.$other->getKey().'/acceso', ['active' => true])->assertRedirect();
        $this->assertTrue($other->refresh()->isActive());
        $this->assertSame(
            [PlatformAudit::OPERATOR_DISABLED, PlatformAudit::OPERATOR_ENABLED],
            PlatformActivity::query()->orderBy('id')->pluck('action')->filter(fn (string $action): bool => str_starts_with($action, 'operator.'))->values()->all(),
        );
    }

    public function test_nobody_disables_themselves(): void
    {
        $me = $this->asOperator();

        $this->patch('/plataforma/operadores/'.$me->getKey().'/acceso', ['active' => false])->assertSessionHasErrors('operator');
        $this->assertTrue($me->refresh()->isActive());
    }

    public function test_the_last_active_operator_is_never_disabled(): void
    {
        $last = $this->operator();
        $retired = $this->operator(['disabled_at' => now()]);

        try {
            app(SetPlatformOperatorAccessAction::class)->handle($last, false, $retired);
            $this->fail('Desactivó al último operador activo.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Tiene que quedar al menos un operador activo.', $exception->getMessage());
        }

        $this->assertTrue($last->refresh()->isActive());
    }
}
