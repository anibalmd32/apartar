<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RoleMatrixTest extends TestCase
{
    use ReusesMigratedDatabase;

    /**
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    public static function governanceMatrix(): array
    {
        return self::matrixOver(OrganizationCapability::ownerOnly(), memberStatus: 403);
    }

    /**
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    public static function dailyMatrix(): array
    {
        return self::matrixOver(OrganizationCapability::shared(), memberStatus: 200);
    }

    /**
     * @return array<string, array{0: OrganizationRole, 1: int}>
     */
    public static function roles(): array
    {
        return [
            'owner' => [OrganizationRole::Owner, 200],
            'member' => [OrganizationRole::Member, 403],
        ];
    }

    #[DataProvider('governanceMatrix')]
    public function test_each_governance_area_answers_by_role(
        OrganizationCapability $capability,
        OrganizationRole $role,
        int $expected,
    ): void {
        $url = $this->probeRouteFor($capability);

        $this->actingAs($this->actor($role))->get($url)->assertStatus($expected);
    }

    #[DataProvider('dailyMatrix')]
    public function test_both_roles_run_the_daily_work(
        OrganizationCapability $capability,
        OrganizationRole $role,
        int $expected,
    ): void {
        $url = $this->probeRouteFor($capability);

        $this->actingAs($this->actor($role))->get($url)->assertStatus($expected);
    }

    #[DataProvider('roles')]
    public function test_a_group_of_owner_routes_is_closed_to_a_member(OrganizationRole $role, int $expected): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'owner'])
            ->get('__roles/seccion-del-dueno', fn (): array => ['ok' => true]);

        $this->actingAs($this->actor($role))->get('/__roles/seccion-del-dueno')->assertStatus($expected);
    }

    public function test_an_authenticated_member_gets_403_and_not_the_login_screen(): void
    {
        Route::middleware(['web', 'auth', 'tenant', 'owner'])
            ->get('__roles/solo-dueno', fn (): array => ['ok' => true]);

        $response = $this->actingAs($this->actor(OrganizationRole::Member))->get('/__roles/solo-dueno');

        $response->assertForbidden();
        $this->assertFalse($response->isRedirect());
    }

    public function test_an_owner_does_not_govern_the_organization_next_door(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $owner = $this->withinOrganization($own, fn (): User => User::factory()->owner()->create());

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('viewSettings', $own));
        $this->assertFalse(Gate::forUser($owner)->allows('viewSettings', $neighbour));
        $this->assertFalse(Gate::forUser($owner)->allows('managePaymentAccounts', $neighbour));
        $this->assertFalse(Gate::forUser($owner)->allows('operate', $neighbour));
    }

    public function test_an_owner_does_not_touch_the_team_next_door(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $owner = $this->withinOrganization($own, fn (): User => User::factory()->owner()->create());
        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());
        $colleague = $this->withinOrganization($own, fn (): User => User::factory()->create());

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $colleague));
        $this->assertFalse(Gate::forUser($owner)->allows('update', $foreign));
        $this->assertFalse(Gate::forUser($owner)->allows('view', $foreign));
    }

    public function test_an_owner_may_change_roles_including_its_own(): void
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        /** @var array{0: User, 1: User} $team */
        $team = $this->withinOrganization($organization, fn (): array => [
            User::factory()->owner()->create(),
            User::factory()->create(),
        ]);

        [$owner, $member] = $team;

        $this->withoutOrganization();

        $this->assertTrue(Gate::forUser($owner)->allows('update', $member));
        $this->assertTrue(Gate::forUser($owner)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($member)->allows('update', $owner));
        $this->assertFalse(Gate::forUser($member)->allows('create', User::class));
    }

    /**
     * @param  list<OrganizationCapability>  $capabilities
     * @return array<string, array{0: OrganizationCapability, 1: OrganizationRole, 2: int}>
     */
    private static function matrixOver(array $capabilities, int $memberStatus): array
    {
        $cells = [];

        foreach ($capabilities as $capability) {
            foreach (OrganizationRole::cases() as $role) {
                $cells[$capability->value.' · '.$role->value] = [
                    $capability,
                    $role,
                    $role === OrganizationRole::Owner ? 200 : $memberStatus,
                ];
            }
        }

        return $cells;
    }

    private function probeRouteFor(OrganizationCapability $capability): string
    {
        $uri = '__roles/'.$capability->value;

        Route::middleware(['web', 'auth', 'tenant'])->get($uri, function () use ($capability) {
            $organization = app(TenantContext::class)->organization();

            match ($capability) {
                OrganizationCapability::Subscription => Gate::authorize('view', Subscription::query()->sole()),
                OrganizationCapability::Billing => Gate::authorize('viewInvoices', Subscription::query()->sole()),
                OrganizationCapability::Team => Gate::authorize('viewAny', User::class),
                OrganizationCapability::Settings => Gate::authorize('viewSettings', $organization),
                OrganizationCapability::PaymentAccounts => Gate::authorize('managePaymentAccounts', $organization),
                OrganizationCapability::Branding => Gate::authorize('manageBranding', $organization),
                OrganizationCapability::Audit => Gate::authorize('viewAuditLog', $organization),

                OrganizationCapability::Products,
                OrganizationCapability::Catalogs,
                OrganizationCapability::Customers,
                OrganizationCapability::Collections,
                OrganizationCapability::Receipts,
                OrganizationCapability::Chat => Gate::authorize('operate', $organization),
            };

            return response()->json(['area' => $capability->value]);
        });

        return '/'.$uri;
    }

    private function actor(OrganizationRole $role): User
    {
        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $actor = $this->withinOrganization($organization, function () use ($role): User {
            Subscription::factory()->create();

            return User::factory()->state(['role' => $role])->create();
        });

        $this->withoutOrganization();

        return $actor;
    }
}
