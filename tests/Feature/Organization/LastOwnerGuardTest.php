<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\LastOwnerException;
use App\Domain\Tenancy\LastOwnerGuard;
use App\Domain\Tenancy\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LastOwnerGuardTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_an_owner_may_step_down_while_another_remains(): void
    {
        $this->organizationWithNeighbour();

        $leaving = User::factory()->owner()->create();
        User::factory()->owner()->create();

        $this->guard()->whileKeepingAnOwner(
            $leaving,
            'degradar',
            fn () => $leaving->update(['role' => OrganizationRole::Member]),
        );

        $this->assertSame(OrganizationRole::Member, $leaving->refresh()->role);
        $this->assertSame(1, User::query()->where('role', OrganizationRole::Owner)->count());
    }

    public function test_the_last_owner_cannot_step_down(): void
    {
        $this->organizationWithNeighbour();

        $only = User::factory()->owner()->create();
        User::factory()->create();

        try {
            $this->guard()->whileKeepingAnOwner(
                $only,
                'degradar',
                fn () => $only->update(['role' => OrganizationRole::Member]),
            );

            $this->fail('Se esperaba LastOwnerException.');
        } catch (LastOwnerException $exception) {
            $this->assertStringContainsString('último owner', $exception->getMessage());
        }

        $this->assertSame(OrganizationRole::Owner, $only->refresh()->role);
    }

    public function test_a_disabled_owner_does_not_count_as_the_one_that_stays(): void
    {
        $this->organizationWithNeighbour();

        $active = User::factory()->owner()->create();
        User::factory()->owner()->disabled()->create();

        $this->expectException(LastOwnerException::class);

        try {
            $this->guard()->whileKeepingAnOwner(
                $active,
                'desactivar',
                fn () => $active->update(['disabled_at' => now()]),
            );
        } finally {
            $this->assertNull($active->refresh()->disabled_at);
        }
    }

    public function test_a_member_may_be_touched_even_with_a_single_owner(): void
    {
        $this->organizationWithNeighbour();

        User::factory()->owner()->create();
        $member = User::factory()->create();

        $result = $this->guard()->whileKeepingAnOwner($member, 'desactivar', fn (): string => 'hecho');

        $this->assertSame('hecho', $result);
    }

    public function test_it_knows_who_is_the_last_active_owner(): void
    {
        $this->organizationWithNeighbour();

        $only = User::factory()->owner()->create();
        $member = User::factory()->create();

        $this->assertTrue($this->guard()->isLastActiveOwner($only));
        $this->assertFalse($this->guard()->isLastActiveOwner($member));

        User::factory()->owner()->create();

        $this->assertFalse($this->guard()->isLastActiveOwner($only));
    }

    public function test_it_refuses_a_subject_from_another_organization(): void
    {
        $own = $this->organizationWithNeighbour();
        $neighbour = Organization::query()->whereKeyNot($own->getKey())->firstOrFail();

        User::factory()->owner()->create();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($own);

        $this->expectException(CrossTenantRelationException::class);

        $this->guard()->whileKeepingAnOwner($foreign, 'degradar', fn (): bool => true);
    }

    public function test_the_http_answer_is_422(): void
    {
        Route::middleware(['web', 'auth', 'tenant'])->post('__roles/degradarme', function () {
            /** @var User $user */
            $user = auth()->user();

            return app(LastOwnerGuard::class)->whileKeepingAnOwner(
                $user,
                'degradar',
                fn () => $user->update(['role' => OrganizationRole::Member]),
            );
        });

        $organization = Organization::factory()->create();
        $this->neighbourOrganization();

        $only = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $response = $this->actingAs($only)->postJson('/__roles/degradarme');

        $response->assertStatus(422);
        $this->assertStringContainsString('último owner', (string) $response->json('errors.role.0'));

        $this->assertSame(OrganizationRole::Owner, $only->refresh()->role);
    }

    private function guard(): LastOwnerGuard
    {
        return app(LastOwnerGuard::class);
    }

    private function organizationWithNeighbour(): Organization
    {
        $organization = Organization::factory()->create();

        $this->neighbourOrganization();
        $this->actingAsOrganization($organization);

        return $organization;
    }
}
