<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Notification\DispatchCatalogDigestAction;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationStatus;
use App\Jobs\SendWebPush;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class DispatchCatalogDigestActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['webpush.vapid.public_key' => 'BPublica', 'webpush.vapid.private_key' => 'privada']);
        Queue::fake();
        Notification::fake();
        $this->organization = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        $this->owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();
    }

    public function test_explicit_selection_reaches_only_subscribed_customers(): void
    {
        $this->onPlan(PlanFeature::WebPush);
        [$reachable, $silent] = $this->customers(2);
        $reachable->updatePushSubscription('https://push.test/1', 'k', 't', 'aes128gcm');

        $this->actingAs($this->owner)
            ->postJson(route('notifications.send'), $this->payload(['customers' => [$reachable->getKey(), $silent->getKey()]]))
            ->assertStatus(202)
            ->assertJsonPath('report.queued', 1)
            ->assertJsonPath('report.unreachable', 1);

        Queue::assertPushed(SendWebPush::class, 1);
    }

    public function test_both_modes_or_none_is_422(): void
    {
        $this->onPlan(PlanFeature::WebPush);
        $this->actingAs($this->owner);

        $this->postJson(route('notifications.send'), $this->payload([]))->assertStatus(422);
        $this->postJson(route('notifications.send'), $this->payload(['customers' => [1], 'segment' => ['preset' => 'due_today']]))->assertStatus(422);
    }

    public function test_a_customer_of_another_business_is_never_reached(): void
    {
        $this->onPlan(PlanFeature::WebPush);
        $neighbour = $this->neighbourOrganization();
        $foreign = $this->withinOrganization($neighbour, fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();
        $foreign->updatePushSubscription('https://push.test/ajeno', 'k', 't', 'aes128gcm');

        $this->actingAs($this->owner)
            ->postJson(route('notifications.send'), $this->payload(['customers' => [$foreign->getKey()]]))
            ->assertStatus(422)
            ->assertJsonPath('report.recipients', 0);

        Queue::assertNothingPushed();
    }

    public function test_free_and_suspended_are_blocked(): void
    {
        $this->onPlan(null);
        [$customer] = $this->customers(1);

        $this->actingAs($this->owner)->postJson(route('notifications.send'), $this->payload(['customers' => [$customer->getKey()]]))->assertForbidden();

        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();
        $this->actingAs($this->owner)->postJson(route('notifications.send'), $this->payload(['customers' => [$customer->getKey()]]))->assertStatus(403);
    }

    public function test_the_fourth_send_in_an_hour_is_429(): void
    {
        $this->onPlan(PlanFeature::WebPush);
        [$customer] = $this->customers(1);
        $customer->updatePushSubscription('https://push.test/1', 'k', 't', 'aes128gcm');
        $this->actingAs($this->owner);

        foreach (range(1, 3) as $ignored) {
            $this->postJson(route('notifications.send'), $this->payload(['customers' => [$customer->getKey()]]))->assertStatus(202);
        }

        $this->postJson(route('notifications.send'), $this->payload(['customers' => [$customer->getKey()]]))->assertStatus(429);
    }

    public function test_free_reports_the_potential_reach_of_the_digest(): void
    {
        $this->onPlan(null);
        $catalog = $this->catalogWithNewProducts();
        $this->follow($catalog, 2);

        $result = app(DispatchCatalogDigestAction::class)->handle($this->organization, CarbonImmutable::now());

        $this->assertSame(['sent' => 0, 'potentialReach' => 2], $result);
        Notification::assertNothingSent();
    }

    public function test_the_digest_goes_once_skips_muted_and_closed_catalogs(): void
    {
        $this->onPlan(PlanFeature::SubscriberDigest);
        $open = $this->catalogWithNewProducts();
        [$listener, $muted] = $this->follow($open, 2);
        CatalogSubscription::query()->where('account_id', $muted->getKey())->update(['muted_at' => now()]);
        $closed = $this->catalogWithNewProducts();
        $this->withinOrganization($this->organization, fn () => $closed->forceFill(['published_at' => null])->save());
        $this->withoutOrganization();
        $this->follow($closed, 1);

        app(DispatchCatalogDigestAction::class)->handle($this->organization, CarbonImmutable::now());
        app(DispatchCatalogDigestAction::class)->handle($this->organization, CarbonImmutable::now());

        Notification::assertSentTimes(CustomerMessageNotification::class, 1);
        Notification::assertSentTo($listener, CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => str_contains($notification->lines[0], '2 productos nuevos'));
    }

    private function onPlan(?PlanFeature $feature): void
    {
        $plan = Plan::factory()->unlimited()->create(['features' => $feature === null ? [] : [$feature->value => true]]);
        $this->withinOrganization($this->organization, fn () => Subscription::factory()->forPlan($plan)->create());
        $this->withoutOrganization();
    }

    /**
     * @return list<Customer>
     */
    private function customers(int $count): array
    {
        $customers = $this->withinOrganization($this->organization, fn (): array => Customer::factory()->count($count)->create()->all());
        $this->withoutOrganization();

        return $customers;
    }

    /**
     * @param  array<string, mixed>  $mode
     * @return array<string, mixed>
     */
    private function payload(array $mode): array
    {
        return ['title' => 'Oferta', 'body' => 'Esta semana, 10% en taladros.', ...$mode];
    }

    private function catalogWithNewProducts(): Catalog
    {
        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            $catalog = Catalog::factory()->published()->create();

            foreach (Product::factory()->published()->count(2)->create() as $index => $product) {
                $catalog->products()->attach($product, ['position' => $index + 1]);
            }

            DB::table('catalog_product')->where('catalog_id', $catalog->getKey())->update(['created_at' => now()->subHours(2)]);

            return $catalog;
        });
        $this->withoutOrganization();

        return $catalog;
    }

    /**
     * @return list<Account>
     */
    private function follow(Catalog $catalog, int $count): array
    {
        $accounts = Account::factory()->count($count)->create()->all();

        foreach ($accounts as $account) {
            CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $catalog->getKey()]);
            $account->updatePushSubscription('https://push.test/'.$account->getKey(), 'k', 't', 'aes128gcm');
        }

        return $accounts;
    }
}
