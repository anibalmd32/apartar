<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Domain\Chat\NoCustomerRecordException;
use App\Events\MessageSent;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\CatalogSubscription;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Organization;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ChatTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Catalog $catalog;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageSent::class]);
        $this->organization = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        [$this->catalog, $this->customer] = $this->withinOrganization($this->organization, fn (): array => [
            Catalog::factory()->published()->create(),
            Customer::factory()->create(),
        ]);
        $this->withoutOrganization();
    }

    public function test_the_organization_comes_from_the_catalog_not_the_payload(): void
    {
        $neighbour = $this->neighbourOrganization();

        $this->actingAs($this->customer, 'customer')
            ->postJson(route('catalog.chat', ['catalog' => $this->catalog->slug]), [
                'body' => 'Hola',
                'idempotencyKey' => (string) Str::uuid(),
                'organization_id' => $neighbour->getKey(),
            ])
            ->assertCreated()
            ->assertJsonPath('threadUrl', route('portal.chat.show', ['organization' => $this->organization->slug]));

        $this->assertSame([(int) $this->organization->getKey()], DB::table('messages')->pluck('organization_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_a_subscriber_without_a_record_cannot_write(): void
    {
        $account = Account::factory()->create();
        CatalogSubscription::factory()->create(['account_id' => $account->getKey(), 'catalog_id' => $this->catalog->getKey()]);

        $this->actingAs($account, 'account')
            ->postJson(route('catalog.chat', ['catalog' => $this->catalog->slug]), ['body' => 'Hola', 'idempotencyKey' => (string) Str::uuid()])
            ->assertForbidden()
            ->assertJsonPath('message', NoCustomerRecordException::MESSAGE);

        $this->assertSame(0, DB::table('messages')->count());
    }

    public function test_writing_to_a_business_without_a_record_is_403(): void
    {
        $neighbour = $this->neighbourOrganization();

        $this->actingAs($this->customer, 'customer')
            ->postJson(route('portal.chat.store', ['organization' => $neighbour->slug]), ['body' => 'Hola', 'idempotencyKey' => (string) Str::uuid()])
            ->assertForbidden();

        $this->actingAs($this->customer, 'customer')->get(route('portal.chat.show', ['organization' => $neighbour->slug]))->assertForbidden();
    }

    public function test_validation_and_idempotency(): void
    {
        $this->actingAs($this->customer, 'customer');
        $url = route('portal.chat.store', ['organization' => $this->organization->slug]);
        $key = (string) Str::uuid();

        $this->postJson($url, ['body' => '   ', 'idempotencyKey' => $key])->assertStatus(422);
        $this->postJson($url, ['body' => str_repeat('a', 2001), 'idempotencyKey' => $key])->assertStatus(422);
        $this->postJson($url, ['body' => 'Hola', 'idempotencyKey' => $key])->assertCreated();
        $this->postJson($url, ['body' => 'Hola', 'idempotencyKey' => $key])->assertCreated();

        $this->assertSame(1, DB::table('messages')->count());
    }

    public function test_the_rate_limit(): void
    {
        $this->actingAs($this->customer, 'customer');
        $url = route('portal.chat.store', ['organization' => $this->organization->slug]);

        for ($i = 0; $i < AppServiceProvider::CHAT_MESSAGES_PER_MINUTE; $i++) {
            $this->postJson($url, ['body' => "Mensaje {$i}", 'idempotencyKey' => (string) Str::uuid()])->assertCreated();
        }

        $this->postJson($url, ['body' => 'Uno más', 'idempotencyKey' => (string) Str::uuid()])->assertStatus(429);
    }

    public function test_opening_marks_read_and_the_account_lists_one_thread_per_business(): void
    {
        $other = Organization::factory()->create(['name' => 'Boutique Sur']);
        $account = Account::factory()->create();
        $elsewhere = $this->withinOrganization($other, fn (): Customer => Customer::factory()->create(['account_id' => $account->getKey()]));
        DB::table('customers')->where('id', $this->customer->getKey())->update(['account_id' => $account->getKey()]);
        $conversation = $this->withinOrganization($this->organization, function (): Conversation {
            $conversation = Conversation::factory()->create(['customer_id' => $this->customer->getKey(), 'customer_unread_count' => 1]);
            Message::factory()->create(['conversation_id' => $conversation->getKey(), 'sender_type' => 'team', 'sender_id' => 1]);

            return $conversation;
        });
        $this->withoutOrganization();

        $this->actingAs($account, 'account')->get(route('portal.chat.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('threads', 2));
        $this->get(route('portal.chat.show', ['organization' => $this->organization->slug]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Chat')
            ->has('thread.messages', 1));

        $this->assertSame(0, (int) DB::table('conversations')->where('id', $conversation->getKey())->value('customer_unread_count'));
        $this->assertNotNull(DB::table('messages')->value('read_at'));

        app('auth')->forgetGuards();
        $this->actingAs($elsewhere, 'customer')->get(route('portal.chat.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('threads', 1));
    }
}
