<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notification\CustomerNotices;
use App\Domain\Notification\NotificationType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Organization;
use App\Notifications\CustomerMessageNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class CustomerNoticesTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_the_rejection_carries_the_full_reason_and_the_business(): void
    {
        Notification::fake();
        $organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $layaway = $this->layawayIn($organization);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);
        $reason = 'La referencia del pago móvil no aparece en nuestro banco; revisa el número y vuelve a enviarla.';

        $this->withinOrganization($organization, fn () => app(CustomerNotices::class)->receiptRejected($layaway, $reason, CarbonImmutable::parse('2026-09-15 20:00:00')));

        Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id), CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => $notification->type === NotificationType::ReceiptRejected
            && str_contains($notification->title, 'Ferretería Oriente')
            && str_contains($notification->lines[0], $reason)
            && count($notification->lines) === 2
            && $notification->push);
    }

    public function test_a_linked_record_is_notified_through_its_account(): void
    {
        Notification::fake();
        $organization = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($organization);
        $account = Account::factory()->create();
        DB::table('customers')->where('id', $layaway->customer_id)->update(['account_id' => $account->getKey()]);

        $this->withinOrganization($organization, fn () => app(CustomerNotices::class)->completed($layaway));

        Notification::assertSentTo($account, CustomerMessageNotification::class);
        Notification::assertNotSentTo(Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id), CustomerMessageNotification::class);
    }
}
