<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Installment;
use App\Models\Organization;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La activación y las cuotas, contra la organización vecina
 * (`REQ-QAX-008` RB-2, `REQ-ORG-002`).
 *
 * `ActivateLayawayAction` busca y bloquea el apartado por el global scope, y
 * las cuotas nacen con la organización del tenant fijado. Lo que se afirma es
 * que con un negocio fijado el apartado del otro no existe —no se activa ni
 * se le escriben cuotas—, que las cuotas de cada uno solo se ven desde el
 * suyo, y que sin tenant no se activa nada.
 */
final class ActivateLayawayIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_a_layaway_of_another_organization_cannot_be_activated(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();
        $neighbours = $this->layawayIn($theirs);

        try {
            $this->withinOrganization($ours, fn () => app(ActivateLayawayAction::class)->handle($neighbours));
            $this->fail('Se activó el apartado de otra organización.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame('pending_down_payment', DB::table('layaways')->where('id', $neighbours->getKey())->value('status'));
        $this->assertSame(0, DB::table('installments')->where('layaway_id', $neighbours->getKey())->count());
    }

    public function test_installments_are_born_in_the_organization_of_their_layaway_and_only_seen_from_it(): void
    {
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        $this->withinOrganization($ours, fn () => app(ActivateLayawayAction::class)->handle($mine));
        $this->withinOrganization($theirs, fn () => app(ActivateLayawayAction::class)->handle($neighbours));

        $this->assertSame(
            [(int) $ours->getKey()],
            DB::table('installments')->where('layaway_id', $mine->getKey())->distinct()->pluck('organization_id')->map(fn ($id): int => (int) $id)->all(),
        );

        $visible = $this->withinOrganization($ours, fn () => Installment::query()->distinct()->pluck('layaway_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([(int) $mine->getKey()], $visible);
    }

    public function test_without_a_tenant_nothing_is_activated(): void
    {
        $organization = Organization::factory()->create();
        $layaway = $this->layawayIn($organization);

        try {
            app(ActivateLayawayAction::class)->handle($layaway);
            $this->fail('Se activó un apartado sin organización fijada.');
        } catch (MissingTenantContextException) {
        }

        $this->assertSame(LayawayStatus::PendingDownPayment->value, DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
        $this->assertSame(0, DB::table('installments')->count());
    }
}
