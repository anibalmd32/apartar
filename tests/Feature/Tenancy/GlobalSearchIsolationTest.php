<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class GlobalSearchIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_neighbour_never_shows_up(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $neighbour = $this->subscribe($this->neighbourOrganization());
        $theirs = $this->layawayIn($neighbour, LayawayStatus::Active);
        $this->withinOrganization($neighbour, function () use ($theirs): void {
            Customer::query()->whereKey($theirs->customer_id)->update(['full_name' => 'Mariela Vecina']);
            Product::factory()->create(['name' => 'Bolso Mariela']);
        });
        $this->withoutOrganization();

        $this->actingAs($owner)->getJson(route('search', ['q' => 'Mariela']))->assertExactJson(['groups' => []]);
        $this->actingAs($owner)->getJson(route('search', ['q' => $theirs->code]))->assertExactJson(['groups' => []]);
    }
}
