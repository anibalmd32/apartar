<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\ManualPush;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class ManualPushIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_neighbour_pushes_and_customers_do_not_reach_us(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $owner = $this->withinOrganization($organization, fn (): User => User::factory()->owner()->create());

        $neighbour = $this->neighbourOrganization();
        $theirs = $this->withinOrganization($neighbour, function (): Customer {
            ManualPush::query()->create(['title' => 'Del vecino', 'body' => 'x', 'mode' => 'segment', 'recipients' => 3, 'queued' => 3]);

            return Customer::factory()->create(['full_name' => 'Ana Vecina']);
        });
        $this->withoutOrganization();

        $this->actingAs($owner)
            ->get(route('notifications.create', ['cliente' => $theirs->getKey()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('history', 0)
                ->where('preselected', null));

        $this->actingAs($owner)
            ->getJson(route('notifications.recipients', ['q' => 'Ana']))
            ->assertJsonCount(0, 'customers');
    }
}
