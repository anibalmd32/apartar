<?php

declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\DetectOverdueAction;
use App\Actions\Notification\DispatchDueRemindersAction;
use App\Actions\Organization\SuspendOrganizationAction;
use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationStatus;
use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Message;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\Concerns\UploadsReceiptFiles;
use Tests\TestCase;

final class SuspensionSparesCustomersTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase, UploadsReceiptFiles;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    private PaymentAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);
        Queue::fake();
        Notification::fake();

        CarbonImmutable::setTestNow('2026-09-09 10:00:00');
        $this->organization = $this->subscribe(Organization::factory()->create(['timezone' => 'America/Caracas']));
        $this->layaway = $this->layawayIn($this->organization);
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($this->layaway));
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->account = $this->withinOrganization($this->organization, fn (): PaymentAccount => PaymentAccount::factory()->createOne());

        app(SuspendOrganizationAction::class)->handle($this->organization);
        $this->withoutOrganization();
        $this->assertSame(OrganizationStatus::Suspended, $this->organization->fresh()?->status);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_layaway_in_progress_keeps_going(): void
    {
        $this->assertSame('active', $this->statusOf());

        $this->withinOrganization($this->organization, fn () => app(DetectOverdueAction::class)->handle(CarbonImmutable::parse('2026-09-29 06:00:00')));

        $this->assertSame('overdue', $this->statusOf());
    }

    public function test_the_customer_portal_works_and_says_nothing_about_the_suspension(): void
    {
        $response = $this->actingAs($this->customer, 'customer')
            ->get(route('portal.layaways.show', ['code' => $this->layaway->code]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('layaway.code', $this->layaway->code)
                ->where('layaway.statusMessage', null)
                ->has('paymentAccounts', 1)
                ->where('receiptUrl', route('portal.receipts.create', ['code' => $this->layaway->code])));

        $this->assertStringNotContainsStringIgnoringCase('suspend', (string) $response->getContent());
    }

    public function test_the_customer_still_uploads_receipts(): void
    {
        $this->actingAs($this->customer, 'customer')
            ->post(route('portal.receipts.store', ['code' => $this->layaway->code]), [
                'paymentAccount' => $this->account->getKey(),
                'method' => 'pago_movil',
                'bank' => 'Banesco',
                'reference' => '0000000001',
                'paidAt' => '2026-09-10',
                'amount' => '850.00',
                'currency' => 'VES',
                'note' => null,
                'file' => $this->receiptImage(),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('portal.layaways.show', ['code' => $this->layaway->code]));

        $this->assertSame(
            ReceiptStatus::PendingReview,
            $this->withinOrganization($this->organization, fn (): ?ReceiptStatus => Receipt::query()->latest('id')->first()?->status),
        );
    }

    public function test_the_chat_with_customers_survives_the_suspension(): void
    {
        Event::fake([MessageSent::class]);
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->customer, 'customer')
            ->postJson(route('portal.chat.store', ['organization' => $this->organization->slug]), ['body' => '¿Puedo pagar el lunes?', 'idempotencyKey' => (string) Str::uuid()])
            ->assertCreated();

        $conversation = $this->withinOrganization($this->organization, fn (): Conversation => Conversation::query()->sole());
        $this->withoutOrganization();

        $this->actingAs($member)
            ->postJson(route('chat.store', ['conversation' => $conversation->getKey()]), ['body' => 'Claro que sí.', 'idempotencyKey' => (string) Str::uuid()])
            ->assertCreated();

        $this->assertSame(2, $this->withinOrganization($this->organization, fn (): int => Message::query()->count()));
    }

    public function test_the_reminders_keep_going_out(): void
    {
        DB::table('customers')->where('id', $this->customer->getKey())->update(['email' => 'maria@ejemplo.test']);
        $first = $this->withinOrganization($this->organization, fn () => $this->layaway->installments()->orderBy('number')->firstOrFail());
        $this->withoutOrganization();

        $now = CarbonImmutable::parse($first->due_date->toDateString().' 08:00:00', 'America/Caracas')->utc();
        $result = app(DispatchDueRemindersAction::class)->handle($this->organization->fresh() ?? $this->organization, $now, installments: true);

        $this->assertSame(1, $result['sent']);
        Notification::assertSentTo($this->customer, CustomerMessageNotification::class);
    }

    private function statusOf(): string
    {
        return (string) DB::table('layaways')->where('id', $this->layaway->getKey())->value('status');
    }
}
