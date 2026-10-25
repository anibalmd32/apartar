<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SuspendedOrganizationTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_a_suspended_team_can_still_approve_a_receipt(): void
    {
        $this->registerBusinessRoutes();

        $this->actingAs($this->teamMember(suspended: true))
            ->post('/__backoffice/comprobantes/1/aprobar')
            ->assertOk()
            ->assertJson(['aprobado' => true]);
    }

    public function test_a_suspended_team_cannot_create_products(): void
    {
        $this->registerBusinessRoutes();

        $response = $this->actingAs($this->teamMember(suspended: true))
            ->post('/__backoffice/productos');

        $response->assertForbidden();
        $this->assertStringContainsString('suspendida', $response->exception?->getMessage() ?? '');
        $this->assertStringContainsString('Suscripción', $response->exception?->getMessage() ?? '');
    }

    public function test_the_same_route_works_while_the_organization_is_active(): void
    {
        $this->registerBusinessRoutes();

        $this->actingAs($this->teamMember())
            ->post('/__backoffice/productos')
            ->assertOk()
            ->assertJson(['creado' => true]);
    }

    public function test_a_route_that_declares_nothing_is_blocked(): void
    {
        Route::middleware($this->backofficeStack())
            ->get('__backoffice/sin-declarar', fn (): array => ['ok' => true]);

        $this->actingAs($this->teamMember(suspended: true))
            ->get('/__backoffice/sin-declarar')
            ->assertForbidden();
    }

    public function test_the_dashboard_survives_the_suspension(): void
    {
        $this->actingAs($this->teamMember(suspended: true))
            ->get('/backoffice')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('backoffice/Dashboard')
                ->where('teamMember.organization.status', 'suspended')
            );
    }

    public function test_the_layout_carries_the_notice_and_the_payment_link(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/layouts/backoffice-layout.tsx'));

        expect($layout)
            ->toContain("=== 'suspended'")
            ->toContain('/backoffice/suscripcion')
            ->toContain('Regularizar el pago');
    }

    public function test_logging_out_survives_the_suspension(): void
    {
        $this->actingAs($this->teamMember(suspended: true))
            ->post('/backoffice/logout')
            ->assertRedirect(route('login'));
    }

    private function registerBusinessRoutes(): void
    {
        Route::middleware([...$this->backofficeStack(), 'during-suspension'])
            ->post('__backoffice/comprobantes/{receipt}/aprobar', fn (): array => ['aprobado' => true]);

        Route::middleware($this->backofficeStack())
            ->post('__backoffice/productos', fn (): array => ['creado' => true]);
    }

    /**
     * @return list<string>
     */
    private function backofficeStack(): array
    {
        return ['web', 'auth', 'tenant', 'admin-active', 'organization-active', 'password-changed'];
    }

    private function teamMember(bool $suspended = false): User
    {
        $organization = Organization::factory()->create($suspended
            ? ['status' => OrganizationStatus::Suspended, 'suspended_at' => now()]
            : []);

        $this->neighbourOrganization();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        return $user;
    }
}
