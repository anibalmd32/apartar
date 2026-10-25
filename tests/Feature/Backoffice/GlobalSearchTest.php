<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class GlobalSearchTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_it_finds_customers_layaways_and_products(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($organization, LayawayStatus::Active);
        [$owner] = $this->withinOrganization($organization, function () use ($layaway): array {
            Customer::query()->whereKey($layaway->customer_id)->update(['full_name' => 'Mariela Rojas']);
            Product::factory()->create(['name' => 'Bolso Mariela']);

            return [User::factory()->owner()->create()];
        });
        $this->withoutOrganization();

        $this->actingAs($owner)
            ->getJson(route('search', ['q' => 'Mariela']))
            ->assertOk()
            ->assertJsonPath('groups.0.key', 'customers')
            ->assertJsonPath('groups.0.items.0.title', 'Mariela Rojas')
            ->assertJsonPath('groups.1.key', 'layaways')
            ->assertJsonPath('groups.1.items.0.title', $layaway->code)
            ->assertJsonPath('groups.2.key', 'products')
            ->assertJsonPath('groups.2.items.0.title', 'Bolso Mariela');

        $this->actingAs($owner)
            ->getJson(route('search', ['q' => mb_strtolower(substr($layaway->code, 0, 6))]))
            ->assertJsonPath('groups.0.items.0.url', route('layaways.show', $layaway));
    }

    public function test_a_short_term_returns_nothing(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $this->actingAs($owner)->getJson(route('search', ['q' => 'a']))->assertExactJson(['groups' => []]);
    }
}
