<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantSupportAccessAction;
use App\Actions\Platform\RevokeSupportAccessAction;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SupportAccessListTest extends TestCase
{
    use ActsAsPlatformOperator;
    use ReusesMigratedDatabase;

    public function test_it_lists_open_accesses_of_every_organization_by_default(): void
    {
        [$first] = $this->businessWithOwner();
        [$second] = $this->businessWithOwner();
        $operator = $this->operator(['name' => 'Rosa Operadora']);
        $grant = app(GrantSupportAccessAction::class);
        $grant->handle($operator, $first, 'El negocio reporta un saldo que no cuadra en un apartado');
        $closed = $grant->handle($operator, $second, 'Revisión de un comprobante duplicado que reporta el dueño');
        app(RevokeSupportAccessAction::class)->handle($closed, $operator);

        $this->asOperator();

        $this->get('/plataforma/soporte')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/support/Index')
            ->where('filter', 'vigentes')
            ->has('grants', 1)
            ->where('grants.0.organization.slug', $first->slug)
            ->where('grants.0.operator', 'Rosa Operadora')
            ->where('grants.0.active', true));

        $this->get('/plataforma/soporte?estado=todos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filter', 'todos')
            ->has('grants', 2)
            ->where('grants.0.organization.slug', $second->slug)
            ->where('grants.0.active', false)
            ->where('grants.0.revokedAt', fn (?string $value): bool => $value !== null));
    }

    public function test_an_expired_access_is_not_listed_as_open(): void
    {
        [$organization] = $this->businessWithOwner();
        app(GrantSupportAccessAction::class)->handle($this->operator(), $organization, 'Revisión de un balance que el negocio reporta mal');

        $this->travelTo(CarbonImmutable::now()->addDays(3));
        $this->asOperator();

        $this->get('/plataforma/soporte')->assertInertia(fn (AssertableInertia $page) => $page->has('grants', 0));
        $this->get('/plataforma/soporte?estado=todos')->assertInertia(fn (AssertableInertia $page) => $page->has('grants', 1)->where('grants.0.active', false));
    }

    public function test_the_list_pages_by_twenty_five(): void
    {
        [$organization] = $this->businessWithOwner();
        $grant = app(GrantSupportAccessAction::class);
        $revoke = app(RevokeSupportAccessAction::class);

        foreach (range(1, 26) as $ignored) {
            $operator = $this->operator();
            $revoke->handle($grant->handle($operator, $organization, 'Revisión periódica de la conciliación de saldos'), $operator);
        }

        $this->asOperator();

        $this->get('/plataforma/soporte?estado=todos')->assertInertia(fn (AssertableInertia $page) => $page->has('grants', 25)->where('hasMore', true));
        $this->get('/plataforma/soporte?estado=todos&pagina=2')->assertInertia(fn (AssertableInertia $page) => $page->has('grants', 1)->where('hasMore', false));
    }

    public function test_the_operator_layout_counts_the_open_accesses(): void
    {
        [$organization] = $this->businessWithOwner();
        app(GrantSupportAccessAction::class)->handle($this->operator(), $organization, 'Revisión de un balance que el negocio reporta mal');
        $this->asOperator();

        $this->get('/plataforma')->assertInertia(fn (AssertableInertia $page) => $page->where('platformOperator.activeSupportGrants', 1));
    }

    public function test_a_guest_is_sent_to_the_platform_login(): void
    {
        $this->get('/plataforma/soporte')->assertRedirect('/plataforma/login');
    }
}
