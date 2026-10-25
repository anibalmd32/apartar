<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\User;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class TenantModelIsolationTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_users_are_only_visible_from_their_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create(['name' => 'Propio']));
        $this->withinOrganization($neighbour, fn () => User::factory()->create(['name' => 'Ajeno']));

        $this->actingAsOrganization($own);

        $this->assertSame(['Propio'], User::query()->pluck('name')->all());
    }

    public function test_the_neighbour_cannot_see_our_users(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create());

        $visible = $this->withinOrganization($neighbour, fn (): int => User::query()->count());

        $this->assertSame(0, $visible);
        $this->assertTrue($this->hasNeighbourOrganization());
    }

    public function test_payment_accounts_are_only_visible_from_their_own_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => PaymentAccount::factory()->create(['holder_name' => 'Propio']));
        $this->withinOrganization($neighbour, fn () => PaymentAccount::factory()->create(['holder_name' => 'Ajeno']));

        $this->actingAsOrganization($own);

        $this->assertSame(['Propio'], PaymentAccount::query()->pluck('holder_name')->all());
    }

    public function test_creating_fills_the_organization_on_both_models(): void
    {
        $organization = Organization::factory()->create();

        [$user, $account] = $this->withinOrganization($organization, fn (): array => [
            User::factory()->create(),
            PaymentAccount::factory()->create(),
        ]);

        $this->assertSame((int) $organization->getKey(), $user->organization_id);
        $this->assertSame((int) $organization->getKey(), $account->organization_id);
    }

    public function test_a_user_of_another_organization_is_not_found_by_id(): void
    {
        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());

        $this->actingAsOrganization(Organization::factory()->create());

        $this->assertNull(User::query()->find($foreign->getKey()));
    }

    public function test_querying_users_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        User::query()->get();
    }

    public function test_querying_payment_accounts_without_a_tenant_throws(): void
    {
        $this->withoutOrganization();

        $this->expectException(MissingTenantContextException::class);

        PaymentAccount::query()->get();
    }

    public function test_seeding_into_the_neighbour_works_through_within_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        $foreign = $this->withinOrganization($neighbour, fn (): User => User::factory()->create());

        $this->assertSame((int) $neighbour->getKey(), $foreign->organization_id);

        $this->assertSame(0, User::query()->count());
    }

    public function test_assigning_the_neighbour_organization_by_hand_throws(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->actingAsOrganization($own);

        $user = new User(['name' => 'Ajeno', 'email' => 'ajeno@example.test', 'role' => 'member']);
        $user->organization_id = (int) $neighbour->getKey();

        $this->expectException(CrossTenantRelationException::class);

        $user->save();
    }

    public function test_the_same_email_repeats_across_organizations_but_not_within_one(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create(['email' => 'duplicado@example.test']));
        $this->withinOrganization($neighbour, fn () => User::factory()->create(['email' => 'duplicado@example.test']));
        $this->assertSame(2, User::withoutTenantScope()->where('email', 'duplicado@example.test')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->withinOrganization($own, fn () => User::factory()->create(['email' => 'duplicado@example.test']));
    }

    public function test_without_tenant_scope_sees_every_organization(): void
    {
        $own = Organization::factory()->create();
        $neighbour = $this->neighbourOrganization();

        $this->withinOrganization($own, fn () => User::factory()->create());
        $this->withinOrganization($neighbour, fn () => User::factory()->create());

        $this->withoutOrganization();

        $this->assertSame(2, User::withoutTenantScope()->count());
    }

    public function test_a_user_relates_back_to_its_organization(): void
    {
        $organization = Organization::factory()->create();

        $user = $this->withinOrganization($organization, fn (): User => User::factory()->create());

        $this->assertTrue($user->organization->is($organization));
    }
}
