<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Subscription\ApplyDowngradeAction;
use App\Actions\Subscription\ChangePlanAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Subscription\PlanChangeDirection;
use App\Domain\Subscription\PlanChangePreview;
use App\Domain\Subscription\PlanDowngradeNeedsConfirmationException;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\AuditEntry;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ChangePlanActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Plan $free;

    private Plan $pro;

    private Plan $business;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-09 12:00:00');

        $this->free = Plan::factory()->create([
            'code' => 'free', 'name' => 'Free', 'position' => 1,
            'max_products' => 2, 'max_catalogs' => 1, 'max_products_per_catalog' => 2, 'max_users' => 1,
        ]);
        $this->pro = Plan::factory()->withEveryFeature()->create([
            'code' => 'pro', 'name' => 'Pro', 'position' => 2,
            'max_products' => 10, 'max_catalogs' => 5, 'max_products_per_catalog' => 10, 'max_users' => 5,
        ]);
        $this->business = Plan::factory()->unlimited()->withEveryFeature()->create(['code' => 'business', 'name' => 'Business', 'position' => 3]);

        $this->organization = Organization::factory()->create();
        $this->owner = $this->withinOrganization($this->organization, function (): User {
            Subscription::factory()->forPlan($this->free)->create();

            return User::factory()->owner()->create();
        });
        $this->recalculateUsage($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_upgrading_applies_the_new_limits_at_once(): void
    {
        $this->within(fn () => Product::factory()->count(2)->create());
        $this->recalculateUsage($this->organization);

        $this->assertCannotCreateProduct();

        CarbonImmutable::setTestNow('2026-10-12 08:00:00');
        $subscription = $this->change($this->pro);

        $this->assertTrue($subscription->plan->is($this->pro));
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertEquals(CarbonImmutable::parse('2026-10-12 08:00:00'), $subscription->current_period_start);
        $this->assertEquals(CarbonImmutable::parse('2026-11-12 08:00:00'), $subscription->current_period_end);
        $this->within(fn () => DB::transaction(fn () => app(LimitEnforcer::class)->assertCanCreate(UsageMetric::Products)));
        $this->assertTrue($this->within(fn (): bool => app(LimitEnforcer::class)->hasFeature(PlanFeature::ExcelExport)));
    }

    public function test_downgrading_is_scheduled_and_takes_nothing_away(): void
    {
        $this->change($this->business);
        $this->within(fn () => Product::factory()->count(3)->create());
        $this->recalculateUsage($this->organization);

        CarbonImmutable::setTestNow('2026-10-19 12:00:00');
        $subscription = $this->change($this->pro);

        $this->assertTrue($subscription->plan->is($this->business));
        $this->assertSame(SubscriptionStatus::Grace, $subscription->status);
        $this->assertTrue($subscription->scheduledPlan?->is($this->pro));
        $this->assertEquals(CarbonImmutable::parse('2026-11-09 12:00:00'), $subscription->scheduled_plan_at);
        $this->assertNull($subscription->cancelled_at, 'Bajar a otro plan de pago no es cancelar.');
        $this->assertSame(3, $this->within(fn (): int => Product::query()->count()));
        $this->within(fn () => DB::transaction(fn () => app(LimitEnforcer::class)->assertCanCreate(UsageMetric::Products, 50)));
        $this->assertTrue($this->within(fn (): bool => app(LimitEnforcer::class)->hasFeature(PlanFeature::CustomDomain)));
    }

    public function test_cancelling_leaves_the_organization_on_free(): void
    {
        $this->change($this->pro);

        $subscription = $this->change($this->free);

        $this->assertTrue($subscription->plan->is($this->pro));
        $this->assertTrue($subscription->scheduledPlan?->is($this->free));
        $this->assertEquals(CarbonImmutable::now(), $subscription->cancelled_at);
        $this->assertNotSame(SubscriptionStatus::Cancelled, $subscription->status, 'Cancelar el plan no es cerrar la organización.');
    }

    public function test_the_preview_lists_what_would_exceed(): void
    {
        $this->change($this->pro);
        $this->within(function (): void {
            $catalog = Catalog::factory()->published()->create(['name' => 'Temporada']);
            $catalog->products()->attach(Product::factory()->count(3)->create()->modelKeys());
            Catalog::factory()->published()->create(['name' => 'Otra']);
        });
        $this->recalculateUsage($this->organization);
        $this->within(fn () => DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->where('metric', 'products')->update(['value' => 4]));

        $preview = $this->within(fn (): PlanChangePreview => PlanChangePreview::forDowngrade(Subscription::query()->with('plan')->firstOrFail(), $this->free));

        $this->assertSame(PlanChangeDirection::Downgrade, $preview->direction);
        $this->assertSame([
            ['metric' => 'catalogs', 'used' => 2, 'limit' => 1, 'excess' => 1, 'consequence' => PlanChangePreview::CONSEQUENCE_UNPUBLISH],
            ['metric' => 'products', 'used' => 4, 'limit' => 2, 'excess' => 2, 'consequence' => PlanChangePreview::CONSEQUENCE_BLOCK_CREATION],
        ], $preview->excesses);
        $this->assertSame(1, $preview->catalogsOverLimitCount);
        $this->assertSame('Temporada', $preview->catalogsOverLimit[0]['name']);
        $this->assertSame(3, $preview->catalogsOverLimit[0]['products']);
        $this->assertContains(PlanFeature::ExcelExport, $preview->lostFeatures);
        $this->assertEquals(CarbonImmutable::parse('2026-11-09 12:00:00'), $preview->effectiveAt);
        $this->assertTrue($preview->hasExcess());

        $this->assertFalse(
            $this->within(fn (): bool => PlanChangePreview::forDowngrade(Subscription::query()->with('plan')->firstOrFail(), $this->pro)->hasExcess()),
            'Al mismo techo no excede nada.',
        );
    }

    public function test_the_preview_boundaries_and_the_per_catalog_only_excess(): void
    {
        $this->change($this->pro);
        $catalog = $this->within(function (): Catalog {
            $catalog = Catalog::factory()->published()->create();
            $catalog->products()->attach(Product::factory()->count(2)->create()->modelKeys());
            $trashed = Product::factory()->create();
            $catalog->products()->attach($trashed->getKey());
            $trashed->delete();

            return $catalog;
        });
        $this->recalculateUsage($this->organization);

        $preview = fn (): PlanChangePreview => $this->within(fn (): PlanChangePreview => PlanChangePreview::forDowngrade(Subscription::query()->with('plan')->firstOrFail(), $this->free));

        $this->assertSame([], $preview()->excesses, 'Un catálogo de un techo de uno y dos productos de dos: en el techo, no por encima.');
        $this->assertFalse($preview()->hasExcess(), 'El producto en la papelera no cuenta en su catálogo.');

        $this->within(fn () => $catalog->products()->attach(Product::factory()->create()->getKey()));
        $this->within(fn () => DB::table('usage_counters')->where('organization_id', $this->organization->getKey())->where('metric', 'products')->update(['value' => 2]));

        $this->assertSame([], $preview()->excesses);
        $this->assertTrue($preview()->hasExcess(), 'Solo el techo por catálogo excede, y eso también es exceso.');

        $this->expectException(PlanDowngradeNeedsConfirmationException::class);
        $this->change($this->free);
    }

    public function test_a_downgrade_that_exceeds_needs_explicit_confirmation(): void
    {
        $this->change($this->pro);
        $this->within(fn () => Product::factory()->count(3)->create());
        $this->recalculateUsage($this->organization);

        try {
            $this->change($this->free);
            $this->fail('Una bajada que excede no se programa sin confirmar.');
        } catch (PlanDowngradeNeedsConfirmationException $exception) {
            $this->assertSame('products', $exception->preview->excesses[0]['metric']);
        }

        $this->assertNull($this->subscription()->scheduled_plan_id);

        $this->assertTrue($this->change($this->free, confirmed: true)->scheduledPlan?->is($this->free));
    }

    public function test_reverting_before_the_period_ends_works(): void
    {
        $this->change($this->pro);
        $period = $this->subscription()->current_period_end;
        $this->change($this->free);

        CarbonImmutable::setTestNow('2026-11-09 11:59:00');
        $subscription = $this->change($this->pro);

        $this->assertTrue($subscription->plan->is($this->pro));
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->scheduled_plan_at);
        $this->assertNull($subscription->cancelled_at);
        $this->assertEquals($period, $subscription->current_period_end, 'Revertir no abre período ni cobra nada.');

        $this->change($this->free);
        $upgraded = $this->change($this->business);

        $this->assertTrue($upgraded->plan->is($this->business));
        $this->assertSame(SubscriptionStatus::Active, $upgraded->status);
        $this->assertNull($upgraded->scheduled_plan_id, 'Subir durante la gracia cancela la bajada.');
        $this->assertNull($upgraded->scheduled_plan_at);
        $this->assertNull($upgraded->cancelled_at, 'Y deja de estar cancelada.');
    }

    public function test_asking_again_for_the_scheduled_downgrade_changes_nothing(): void
    {
        $this->change($this->pro);
        $this->change($this->free);
        $entries = $this->auditEntries();

        $this->change($this->free);

        $this->assertSame($entries, $this->auditEntries());
    }

    public function test_a_delinquent_subscription_keeps_its_status_and_its_due_date(): void
    {
        $this->change($this->pro);
        $due = CarbonImmutable::parse('2026-10-01 12:00:00');

        foreach ([SubscriptionStatus::PastDue, SubscriptionStatus::Suspended] as $status) {
            $this->within(fn () => Subscription::query()->update([
                'status' => $status, 'plan_id' => $this->pro->getKey(), 'current_period_end' => $due,
                'scheduled_plan_id' => null, 'scheduled_plan_at' => null, 'cancelled_at' => null,
            ]));

            $scheduled = $this->change($this->free);
            $this->assertSame($status, $scheduled->status, $status->value);
            $this->assertEquals(CarbonImmutable::now(), $scheduled->scheduled_plan_at, 'Sin período pagado, vence ya.');

            $reverted = $this->change($this->pro);
            $this->assertSame($status, $reverted->status, 'Revertir no levanta el impago.');
            $this->assertNull($reverted->scheduled_plan_id);

            $upgraded = $this->change($this->business);
            $this->assertEquals($due, $upgraded->current_period_end, 'Subir no corre el vencimiento de la deuda.');
        }
    }

    public function test_a_downgrade_after_the_period_ended_is_due_now(): void
    {
        $this->within(fn () => Subscription::query()->update(['plan_id' => $this->pro->getKey(), 'current_period_end' => CarbonImmutable::now()->subDay()]));

        $this->assertEquals(CarbonImmutable::now(), $this->change($this->free)->scheduled_plan_at);
    }

    public function test_free_is_always_a_downgrade_even_from_a_negotiated_plan(): void
    {
        $negotiated = Plan::factory()->unlimited()->hidden()->create(['position' => 0]);
        $this->within(fn () => Subscription::query()->update(['plan_id' => $negotiated->getKey(), 'current_period_end' => CarbonImmutable::parse('2026-12-01')]));

        $subscription = $this->change($this->free);

        $this->assertTrue($subscription->plan->is($negotiated));
        $this->assertTrue($subscription->scheduledPlan?->is($this->free));
        $this->assertNotNull($subscription->cancelled_at);
    }

    public function test_upgrading_to_a_priced_plan_issues_its_invoice(): void
    {
        $this->pro->forceFill(['price_cents' => 1_500])->save();
        $this->organization->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $subscription = $this->change($this->pro);

        $invoice = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::query()->sole());
        $this->assertTrue($subscription->plan->is($this->pro));
        $this->assertSame(InvoiceKind::PlanChange, $invoice->kind);
        $this->assertSame(1_500, $invoice->amount_cents);
        $this->assertSame(InvoiceStatus::Pending, $invoice->status);
        $this->assertSame($this->pro->getKey(), $invoice->plan_id);
        $this->assertEquals($subscription->current_period_start, $invoice->period_start);
        $this->assertEquals($subscription->current_period_end, $invoice->period_end);
        $this->assertSame('2026-10-10', $invoice->due_date->toDateString());
        $this->assertNotSame(CarbonImmutable::now()->toDateString(), $invoice->due_date->toDateString(), 'El día de la plataforma no es el del negocio.');
        $this->assertSame(SubscriptionStatus::Active, $subscription->status, 'Mientras se paga sigue al día; el impago se persigue desde el vencimiento.');

        $this->change($this->pro);
        $this->change($this->free);
        $this->assertSame(1, $this->within(fn (): int => SubscriptionInvoice::query()->count()), 'Ni el mismo plan ni bajar facturan.');
    }

    public function test_a_plan_change_voids_the_invoices_it_supersedes_but_never_a_debt(): void
    {
        $this->pro->forceFill(['price_cents' => 1_500])->save();
        $this->business->forceFill(['price_cents' => 3_900])->save();

        $this->change($this->pro);
        $renewal = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::factory()->forPlan($this->pro)->create([
            'period_start' => CarbonImmutable::parse('2026-11-09 12:00:00'),
            'period_end' => CarbonImmutable::parse('2026-12-09 12:00:00'),
            'due_date' => '2026-11-09',
        ]));
        $debt = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::factory()->forPlan($this->pro)->create(['due_date' => '2026-10-01']));

        $this->change($this->business);

        $statuses = $this->within(fn (): array => SubscriptionInvoice::query()->orderBy('id')->pluck('status', 'id')->map(fn (InvoiceStatus $status): string => $status->value)->all());
        $this->assertSame('void', $statuses[$renewal->getKey()], 'La renovación de Pro ya no corresponde.');
        $this->assertSame('pending', $statuses[$debt->getKey()], 'Lo vencido sigue siendo una deuda.');
        $this->assertSame(['void', 'pending'], array_values(array_diff_key($statuses, [$renewal->getKey() => 0, $debt->getKey() => 0])), 'La factura de Pro sin pagar se anula; la de Business, recién emitida, queda.');
        $this->assertSame(ChangePlanAction::SUPERSEDED_REASON, $this->within(fn (): ?string => SubscriptionInvoice::query()->whereKey($renewal->getKey())->value('void_reason')));

        $businessRenewal = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::factory()->forPlan($this->business)->create([
            'period_start' => CarbonImmutable::parse('2026-11-09 12:00:00'),
            'period_end' => CarbonImmutable::parse('2026-12-09 12:00:00'),
            'due_date' => '2026-11-09',
        ]));
        $this->change($this->pro);
        $this->assertSame(InvoiceStatus::Void, $businessRenewal->fresh()?->status);

        $this->within(fn () => Subscription::query()->update(['status' => SubscriptionStatus::PastDue, 'scheduled_plan_id' => null, 'scheduled_plan_at' => null]));
        $kept = $this->within(fn (): SubscriptionInvoice => SubscriptionInvoice::factory()->forPlan($this->business)->create([
            'period_start' => CarbonImmutable::parse('2026-11-09 12:00:00'),
            'period_end' => CarbonImmutable::parse('2026-12-09 12:00:00'),
            'due_date' => '2026-11-09',
        ]));
        $this->change($this->pro);
        $this->assertSame(InvoiceStatus::Pending, $kept->fresh()?->status);
    }

    public function test_a_priced_upgrade_without_a_tax_id_is_refused_before_anything_changes(): void
    {
        $this->organization->forceFill(['document' => null])->save();
        $this->pro->forceFill(['price_cents' => 1_500])->save();

        try {
            $this->change($this->pro);
            $this->fail('Sin RIF no se emite la primera factura.');
        } catch (OrganizationDocumentRequiredException) {
            $this->assertTrue($this->subscription()->plan->is($this->free));
            $this->assertSame(0, $this->within(fn (): int => SubscriptionInvoice::query()->count()));
        }

        $this->assertTrue($this->change($this->business)->plan->is($this->business), 'Business vale cero en este test: no hay factura y no hace falta el RIF.');
    }

    public function test_the_same_plan_is_a_no_op_and_real_changes_are_audited(): void
    {
        $saves = 0;
        Subscription::saved(function () use (&$saves): void {
            $saves++;
        });

        $this->change($this->free);

        $this->assertSame(0, $saves, 'Al mismo plan no se escribe nada.');
        $this->assertSame(0, $this->auditEntries());

        $this->change($this->pro);
        $this->change($this->free);

        $this->assertSame(2, $this->auditEntries());

        $entry = $this->within(fn (): AuditEntry => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionPlanChanged)->latest('id')->firstOrFail());
        $this->assertSame($this->owner->getKey(), $entry->causer_id);

        $changed = $entry->properties['changed'] ?? [];
        $this->assertSame(['after' => 'free', 'before' => null], $changed['scheduled_plan'] ?? null, 'Dice a qué plan baja, y que antes no había nada.');
        $this->assertSame(['after' => 'grace', 'before' => 'active'], $changed['status'] ?? null);
        $this->assertArrayHasKey('cancelled_at', $changed, 'Bajar a Free es una baja, y se ve.');
    }

    public function test_upgrading_with_an_unpaid_invoice_or_suspended_is_allowed(): void
    {
        foreach ([SubscriptionStatus::PastDue, SubscriptionStatus::Suspended] as $status) {
            $this->within(fn () => Subscription::query()->update(['status' => $status, 'plan_id' => $this->free->getKey()]));

            $subscription = $this->change($this->pro);

            $this->assertTrue($subscription->plan->is($this->pro), $status->value);
            $this->assertSame($status, $subscription->status, $status->value);
        }
    }

    public function test_a_member_cannot_change_the_plan(): void
    {
        $member = $this->within(fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        $this->expectException(AuthorizationException::class);

        app(ChangePlanAction::class)->handle($this->organization, $this->pro, $member);
    }

    public function test_an_owner_of_another_organization_cannot_change_this_plan(): void
    {
        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        try {
            app(ChangePlanAction::class)->handle($this->organization, $this->pro, $stranger);
            $this->fail('El dueño de otra organización no gobierna esta suscripción.');
        } catch (AuthorizationException) {
            $this->assertTrue($this->subscription()->plan->is($this->free));
        }
    }

    public function test_a_hidden_plan_is_not_for_sale(): void
    {
        $hidden = Plan::factory()->unlimited()->hidden()->create(['position' => 9]);

        $this->expectException(InvalidArgumentException::class);

        $this->change($hidden);
    }

    public function test_a_downgrade_without_a_paid_period_is_due_now(): void
    {
        $this->within(fn () => Subscription::query()->update(['plan_id' => $this->pro->getKey(), 'current_period_end' => null]));

        $subscription = $this->change($this->free);

        $this->assertEquals(CarbonImmutable::now(), $subscription->scheduled_plan_at);
        $this->assertTrue($subscription->plan->is($this->pro));
    }

    public function test_cancelling_leaves_the_organization_on_free_when_the_period_ends(): void
    {
        $this->within(fn () => Subscription::query()->update(['plan_id' => $this->pro->getKey(), 'current_period_end' => '2026-11-01 00:00:00']));
        $this->change($this->free);
        $cancelledAt = $this->subscription()->cancelled_at;

        CarbonImmutable::setTestNow('2026-11-01 00:05:00');
        $this->withoutOrganization();
        app(ApplyDowngradeAction::class)->handle($this->subscription());

        $subscription = $this->subscription();
        $this->assertTrue($subscription->plan->is($this->free));
        $this->assertNull($subscription->scheduled_plan_id);
        $this->assertNull($subscription->current_period_end);
        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($cancelledAt);
        $this->assertEquals($cancelledAt, $subscription->cancelled_at);
    }

    private function change(Plan $target, bool $confirmed = false): Subscription
    {
        $subscription = app(ChangePlanAction::class)->handle($this->organization, $target, $this->owner, $confirmed);
        $subscription->load(['plan', 'scheduledPlan']);

        return $subscription;
    }

    private function subscription(): Subscription
    {
        return $this->within(fn (): Subscription => Subscription::query()->with(['plan', 'scheduledPlan'])->firstOrFail());
    }

    private function auditEntries(): int
    {
        return $this->within(fn (): int => AuditEntry::query()->ofEvent(AuditEvent::SubscriptionPlanChanged)->count());
    }

    private function assertCannotCreateProduct(): void
    {
        try {
            $this->within(fn () => DB::transaction(fn () => app(LimitEnforcer::class)->assertCanCreate(UsageMetric::Products)));
            $this->fail('Con Free lleno, crear un producto tenía que rechazarse.');
        } catch (PlanLimitReachedException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function within(\Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
