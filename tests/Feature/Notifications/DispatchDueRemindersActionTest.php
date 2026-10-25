<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Actions\Layaway\ExpireLayawaysAction;
use App\Actions\Layaway\MarkDeliveredAction;
use App\Actions\Notification\DispatchDueRemindersAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Notification\NotificationType;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\CustomerMessageNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class DispatchDueRemindersActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->organization = $this->business('Ferretería Oriente');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_one_reminder_per_offset_at_the_boundary(): void
    {
        $first = $this->firstInstallment($this->activeLayaway($this->organization, 'maria@ejemplo.test'));

        foreach ([-3 => 1, -2 => 0, 0 => 1, 1 => 1, 2 => 0, 7 => 1, 15 => 1] as $offset => $expected) {
            $now = $this->eightAm($first->due_date->toImmutable()->addDays($offset), $this->organization);
            $result = app(DispatchDueRemindersAction::class)->handle($this->organization, $now, installments: true);

            $this->assertSame($expected, $result['sent'], "offset {$offset}");
        }
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $first = $this->firstInstallment($this->activeLayaway($this->organization, 'maria@ejemplo.test'));
        $now = $this->eightAm($first->due_date->toImmutable(), $this->organization);
        Notification::fake();

        app(DispatchDueRemindersAction::class)->handle($this->organization, $now, installments: true);
        app(DispatchDueRemindersAction::class)->handle($this->organization, $now, installments: true);

        Notification::assertSentTimes(CustomerMessageNotification::class, 1);
        $this->assertSame(1, DB::table('notification_dispatches')->count());
    }

    public function test_grouped_by_business_and_never_across_businesses(): void
    {
        $one = $this->activeLayaway($this->organization, 'maria@ejemplo.test');
        $two = $this->activeLayaway($this->organization, 'maria@ejemplo.test', customerId: $one->customer_id);
        $first = $this->firstInstallment($one);
        $now = $this->eightAm($first->due_date->toImmutable(), $this->organization);
        Notification::fake();

        app(DispatchDueRemindersAction::class)->handle($this->organization, $now, installments: true);

        Notification::assertSentTimes(CustomerMessageNotification::class, 1);
        Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->findOrFail($one->customer_id), CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => count($notification->lines) === 2
            && str_contains($notification->title, 'Ferretería Oriente'));
        $this->assertNotSame($one->code, $two->code);

        $other = $this->business('Boutique Sur');
        $elsewhere = $this->firstInstallment($this->activeLayaway($other, 'maria@ejemplo.test'));
        Notification::fake();
        app(DispatchDueRemindersAction::class)->handle($other, $this->eightAm($elsewhere->due_date->toImmutable(), $other), installments: true);

        Notification::assertSentTimes(CustomerMessageNotification::class, 1);
    }

    public function test_a_paid_installment_is_not_reminded(): void
    {
        $first = $this->firstInstallment($this->activeLayaway($this->organization, 'maria@ejemplo.test'));
        DB::table('installments')->where('id', $first->getKey())->update(['paid_cents' => DB::raw('amount_cents')]);

        $this->assertSame(0, app(DispatchDueRemindersAction::class)->handle($this->organization, $this->eightAm($first->due_date->toImmutable(), $this->organization), installments: true)['sent']);
    }

    public function test_unreachable_customers_are_counted_and_the_plan_gates_push(): void
    {
        $silent = $this->firstInstallment($this->activeLayaway($this->organization, null));
        $result = app(DispatchDueRemindersAction::class)->handle($this->organization, $this->eightAm($silent->due_date->toImmutable(), $this->organization), installments: true);

        $this->assertSame(['sent' => 0, 'unreachable' => 1], $result);
        Notification::assertNothingSent();

        $reachable = $this->firstInstallment($this->activeLayaway($this->organization, 'maria@ejemplo.test'));
        app(DispatchDueRemindersAction::class)->handle($this->organization, $this->eightAm($reachable->due_date->toImmutable()->addDay(), $this->organization), installments: true);

        Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->whereNotNull('email')->firstOrFail(), CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => $notification->push === false);
    }

    public function test_the_command_sends_at_eight_in_each_timezone(): void
    {
        $madrid = $this->business('Tienda Madrid', 'Europe/Madrid');
        $caracas = $this->firstInstallment($this->activeLayaway($this->organization, 'a@ejemplo.test'));
        $this->firstInstallment($this->activeLayaway($madrid, 'b@ejemplo.test'));

        CarbonImmutable::setTestNow($this->eightAm($caracas->due_date->toImmutable(), $this->organization));
        $this->artisan('notifications:dispatch-reminders')->assertSuccessful();

        $this->assertSame([(int) $this->organization->getKey()], DB::table('notification_dispatches')->where('type', NotificationType::InstallmentReminder->value)->pluck('organization_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_the_down_payment_reminder_goes_twelve_hours_before_once(): void
    {
        $layaway = $this->layawayIn($this->organization);
        $this->withoutOrganization();
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);
        $expires = CarbonImmutable::parse('2026-09-12 16:00:00');
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expires]);

        $this->assertSame(0, app(DispatchDueRemindersAction::class)->handle($this->organization, $expires->subHours(13), installments: false)['sent']);
        $this->assertSame(1, app(DispatchDueRemindersAction::class)->handle($this->organization, $expires->subHours(11), installments: false)['sent']);
        $this->assertSame(0, app(DispatchDueRemindersAction::class)->handle($this->organization, $expires->subHours(10), installments: false)['sent']);
    }

    public function test_transitions_notify_and_a_rollback_does_not(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);

        try {
            DB::transaction(function () use ($layaway): void {
                $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));

                throw new RuntimeException('se deshace');
            });
        } catch (RuntimeException) {
        }
        $this->withoutOrganization();
        Notification::assertNothingSent();

        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $this->withoutOrganization();

        Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id), CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => $notification->type === NotificationType::LayawayActivated);
    }

    public function test_delivery_notifies_once(): void
    {
        $layaway = $this->layawayIn($this->organization, LayawayStatus::Completed);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $this->withinOrganization($this->organization, function () use ($layaway, $owner): void {
            app(MarkDeliveredAction::class)->handle($layaway, $owner);
            app(MarkDeliveredAction::class)->handle($layaway, $owner);
        });

        Notification::assertSentTimes(CustomerMessageNotification::class, 1);
    }

    public function test_expiry_notifies(): void
    {
        $layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);
        $expires = CarbonImmutable::parse('2026-09-12 16:00:00');
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expires]);

        $this->withinOrganization($this->organization, fn () => app(ExpireLayawaysAction::class)->handle($expires->addHour()));

        Notification::assertSentTo(Customer::query()->withoutGlobalScopes()->findOrFail($layaway->customer_id), CustomerMessageNotification::class, fn (CustomerMessageNotification $notification): bool => $notification->type === NotificationType::LayawayExpired);
    }

    private function business(string $name, string $timezone = 'America/Caracas'): Organization
    {
        $organization = Organization::factory()->create(['name' => $name, 'timezone' => $timezone]);
        $this->withinOrganization($organization, fn () => Subscription::factory()->forPlan(Plan::factory()->create(['features' => []]))->create());
        $this->withoutOrganization();

        return $organization;
    }

    private function activeLayaway(Organization $organization, ?string $email, ?int $customerId = null): Layaway
    {
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $layaway = $this->layawayIn($organization);

        if ($customerId !== null) {
            DB::table('layaways')->where('id', $layaway->getKey())->update(['customer_id' => $customerId]);
            $layaway->customer_id = $customerId;
        }

        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => $email]);
        $this->withinOrganization($organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        $this->withoutOrganization();
        CarbonImmutable::setTestNow();

        return $layaway;
    }

    private function firstInstallment(Layaway $layaway): Installment
    {
        return Installment::query()->withoutGlobalScopes()->where('layaway_id', $layaway->getKey())->orderBy('number')->firstOrFail();
    }

    private function eightAm(CarbonImmutable $day, Organization $organization): CarbonImmutable
    {
        return CarbonImmutable::parse($day->toDateString().' 08:00:00', $organization->timezone)->utc();
    }
}
