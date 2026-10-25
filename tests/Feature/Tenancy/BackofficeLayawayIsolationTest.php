<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Installment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class BackofficeLayawayIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_neighbours_layaway_and_installments_are_not_found_and_stay_untouched(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $theirs = $this->subscribe($this->neighbourOrganization());
        $actor = $this->withinOrganization($ours, fn (): User => User::factory()->owner()->create());

        $completed = $this->layawayIn($theirs, LayawayStatus::Completed);
        $pending = $this->layawayIn($theirs);
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($theirs, fn () => app(ActivateLayawayAction::class)->handle($pending));
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $installment = $this->withinOrganization($theirs, fn (): Installment => Installment::query()->where('layaway_id', $pending->getKey())->where('number', 2)->firstOrFail());
        $this->withoutOrganization();

        $this->actingAs($actor);

        $this->get('/backoffice/apartados/'.$pending->code)->assertNotFound();
        $this->post('/backoffice/apartados/'.$pending->code.'/cancelar', ['reason' => 'Intento desde otro negocio.'])->assertNotFound();
        $this->post('/backoffice/apartados/'.$completed->code.'/entregar')->assertNotFound();
        $this->patch('/backoffice/cuotas/'.$installment->getKey().'/reprogramar', ['due_date' => '2026-10-20', 'reason' => 'Intento desde otro negocio.'])->assertNotFound();

        $this->assertSame('active', DB::table('layaways')->where('id', $pending->getKey())->value('status'));
        $this->assertNull(DB::table('layaways')->where('id', $completed->getKey())->value('delivered_at'));
        $this->assertSame('2026-10-10', DB::table('installments')->where('id', $installment->getKey())->value('due_date'));

        CarbonImmutable::setTestNow();
    }
}
