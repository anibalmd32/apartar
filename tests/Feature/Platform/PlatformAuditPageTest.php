<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\PlatformActivity;
use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PlatformAuditPageTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_it_lists_the_newest_entries_first_with_operator_label_and_organization(): void
    {
        [$organization] = $this->businessWithOwner();
        $operator = $this->operator(['name' => 'Rosa Operadora']);
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00'));
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entró al panel.', $operator);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00'));
        PlatformAudit::record(PlatformAudit::PLAN_OVERRIDDEN, 'Cambió el plan a Pro: cortesía comercial', $operator, (int) $organization->getKey());

        $this->asOperator();

        $this->get('/plataforma/auditoria')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/audit/Index')
            ->where('entries.0.action', PlatformAudit::PLAN_OVERRIDDEN)
            ->where('entries.0.label', 'Cambió el plan')
            ->where('entries.0.operator', 'Rosa Operadora')
            ->where('entries.0.organization.slug', $organization->slug)
            ->where('entries.1.action', PlatformAudit::LOGIN)
            ->where('entries.1.organization', null)
            ->where('actions', fn (Collection $actions): bool => $actions->pluck('value')->contains(PlatformAudit::SUPPORT_GRANTED)));
    }

    public function test_it_filters_by_operator_action_and_dates(): void
    {
        $rosa = $this->operator();
        $luis = $this->operator();
        $this->travelTo(CarbonImmutable::parse('2026-10-01 09:00'));
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entrada antigua', $rosa);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00'));
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entrada de Rosa', $rosa);
        PlatformAudit::record(PlatformAudit::INVOICE_VOIDED, 'Anuló F-2026-0001', $rosa);
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entrada de Luis', $luis);

        $this->asOperator();

        $this->get('/plataforma/auditoria?operador='.$rosa->getKey().'&accion='.PlatformAudit::LOGIN.'&desde=2026-10-10&hasta=2026-10-20')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('entries', 1)
                ->where('entries.0.description', 'Entrada de Rosa')
                ->where('filters.operador', $rosa->getKey())
                ->where('filters.desde', '2026-10-10'));
    }

    public function test_unknown_filters_are_ignored(): void
    {
        PlatformAudit::record(PlatformAudit::LOGIN, 'Entrada', $this->operator());
        $this->asOperator();

        $this->get('/plataforma/auditoria?accion=inventada&desde=ayer')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.accion', null)
            ->where('filters.desde', null)
            ->has('entries', PlatformActivity::query()->count()));
    }
}
