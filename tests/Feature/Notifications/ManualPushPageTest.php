<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notification\ManualPushData;
use App\Actions\Notification\SendManualPushAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ManualPushPageTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_a_sent_push_shows_in_the_history_with_the_hourly_limit(): void
    {
        $customer = $this->customer(['full_name' => 'Ana Pérez'], reachable: true);

        $this->withinOrganization($this->organization, fn (): array => app(SendManualPushAction::class)->handle(
            new ManualPushData('Tu cuota vence pronto', 'Recuerda subir tu comprobante.', null, [$customer->getKey()], null),
            $this->owner,
        ));
        $this->withoutOrganization();

        $this->actingAs($this->owner)
            ->get(route('notifications.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('hourlyLimit', 3)
                ->has('history', 1)
                ->where('history.0.title', 'Tu cuota vence pronto')
                ->where('history.0.mode', 'selection')
                ->where('history.0.recipients', 1)
                ->where('history.0.queued', 1)
                ->where('history.0.sender', $this->owner->name));
    }

    public function test_the_search_finds_customers_and_says_who_can_receive_it(): void
    {
        $this->customer(['full_name' => 'Ana Pérez'], reachable: true);
        $this->customer(['full_name' => 'Ana Rojas']);
        $this->customer(['full_name' => 'Beatriz Gil']);

        $this->actingAs($this->owner)
            ->getJson(route('notifications.recipients', ['q' => 'Ana']))
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('customers.0.name', 'Ana Pérez')
            ->assertJsonPath('customers.0.reachable', true)
            ->assertJsonPath('customers.1.reachable', false);
    }

    public function test_notify_from_the_profile_arrives_with_the_customer_chosen(): void
    {
        $customer = $this->customer(['full_name' => 'Ana Pérez']);

        $this->actingAs($this->owner)
            ->get(route('notifications.create', ['cliente' => $customer->getKey()]))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preselected.id', $customer->getKey())
                ->where('preselected.name', 'Ana Pérez')
                ->where('preselected.reachable', false));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes, bool $reachable = false): Customer
    {
        $customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::factory()->create($attributes));
        $this->withoutOrganization();

        if ($reachable) {
            $customer->updatePushSubscription('https://push.test/'.$customer->getKey(), 'k', 't', 'aes128gcm');
        }

        return $customer;
    }
}
