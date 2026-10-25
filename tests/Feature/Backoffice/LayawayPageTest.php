<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class LayawayPageTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_page_shows_the_layaway_its_schedule_and_what_can_be_done(): void
    {
        $layaway = $this->scheduled();
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['paid_cents' => 3_000, 'status' => 'paid']);
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');

        $this->actingAs($this->member)->get(route('layaways.show', $layaway))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/layaways/Show')
            ->where('layaway.code', $layaway->code)
            ->where('layaway.statusLabel', 'Activo')
            ->where('layaway.cancelUrl', route('layaways.cancel', $layaway))
            ->where('layaway.deliverUrl', null)
            ->has('installments', 5)
            ->where('installments.0.reschedule', null)
            ->where('installments.1.reschedule.earliest', '2026-10-02')
            ->where('installments.1.reschedule.latest', '2026-10-25')
            ->where('installments.4.reschedule.latest', null));
    }

    public function test_the_activity_is_composed_from_the_rows_newest_first(): void
    {
        $layaway = $this->scheduled();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMonths(3));

        $this->actingAs($this->member)
            ->post(route('layaways.cancel', $layaway), ['reason' => 'No volvió a pagar desde agosto.'])
            ->assertRedirect();

        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.0.kind', 'cancelled')
            ->where('activity.0.detail', 'No volvió a pagar desde agosto.')
            ->where('activity', fn (iterable $activity): bool => in_array('installment_overdue', array_column(iterator_to_array($activity), 'kind'), true)
                && in_array('created', array_column(iterator_to_array($activity), 'kind'), true))
            ->where('customer.url', route('customers.show', $layaway->customer_id))
            ->where('customer.chatUrl', null)
            ->has('customer.email'));
    }

    public function test_a_cancelled_layaway_shows_its_reason_to_the_business(): void
    {
        $layaway = $this->scheduled();

        $this->actingAs($this->member)
            ->post(route('layaways.cancel', $layaway), ['reason' => 'No volvió a pagar desde agosto.'])
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status');

        $this->get(route('layaways.show', $layaway))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaway.status', 'cancelled')
            ->where('layaway.cancellationReason', 'No volvió a pagar desde agosto.')
            ->where('layaway.cancelUrl', null)
            ->where('installments.1.reschedule', null));
    }

    public function test_a_short_reason_or_a_terminal_layaway_cannot_be_cancelled(): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->withoutOrganization();
        $this->actingAs($this->member);

        $this->from(route('layaways.show', $active))->post(route('layaways.cancel', $active), ['reason' => 'Moroso'])->assertSessionHasErrors('reason');
        $this->from(route('layaways.show', $completed))
            ->post(route('layaways.cancel', $completed), ['reason' => 'Se equivocó de producto.'])
            ->assertSessionHasErrors(['reason' => 'Este apartado está pagado y ya no se puede cancelar.']);

        $this->assertSame('active', DB::table('layaways')->where('id', $active->getKey())->value('status'));
        $this->assertSame('completed', DB::table('layaways')->where('id', $completed->getKey())->value('status'));
    }

    public function test_only_a_completed_layaway_can_be_delivered(): void
    {
        $active = $this->layawayIn($this->organization, LayawayStatus::Active);
        $completed = $this->layawayIn($this->organization, LayawayStatus::Completed);
        $this->withoutOrganization();
        $this->actingAs($this->member);

        $this->from(route('layaways.show', $active))->post(route('layaways.deliver', $active))->assertSessionHasErrors('delivery');
        $this->post(route('layaways.deliver', $completed))->assertRedirect(route('layaways.show', $completed));
        $this->post(route('layaways.deliver', $completed))->assertRedirect(route('layaways.show', $completed));

        $this->assertNotNull(DB::table('layaways')->where('id', $completed->getKey())->value('delivered_at'));
    }

    public function test_rescheduling_answers_in_the_date_field(): void
    {
        $layaway = $this->scheduled();
        $second = $this->withinOrganization($this->organization, fn (): Installment => Installment::query()->where('layaway_id', $layaway->getKey())->where('number', 2)->firstOrFail());
        $this->withoutOrganization();
        CarbonImmutable::setTestNow('2026-10-01 12:00:00');
        $this->actingAs($this->member);

        $this->from(route('layaways.show', $layaway))
            ->patch(route('installments.reschedule', $second), ['due_date' => '2026-10-26', 'reason' => 'Pactado por chat con ella.'])
            ->assertSessionHasErrors(['due_date' => 'La nueva fecha de la cuota 2 tiene que estar entre el 2 de octubre de 2026 y el 25 de octubre de 2026.']);

        $this->patch(route('installments.reschedule', $second), ['due_date' => '2026-10-20', 'reason' => 'Pactado por chat con ella.'])
            ->assertRedirect(route('layaways.show', $layaway))
            ->assertSessionHas('status', 'Cuota 2 reprogramada.');

        $this->assertSame('2026-10-20', DB::table('installments')->where('id', $second->getKey())->value('due_date'));
    }

    public function test_the_operations_work_with_the_organization_suspended(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Active);
        $this->withoutOrganization();
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAs($this->member);

        $this->get(route('layaways.show', $layaway))->assertOk();
        $this->post(route('layaways.cancel', $layaway), ['reason' => 'Cierre pactado con el cliente.'])->assertRedirect(route('layaways.show', $layaway));

        $this->assertSame('cancelled', DB::table('layaways')->where('id', $layaway->getKey())->value('status'));
    }

    private function scheduled(): Layaway
    {
        $layaway = $this->layawayIn($this->organization);
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $this->withoutOrganization();
        CarbonImmutable::setTestNow();

        return $layaway->refresh();
    }
}
