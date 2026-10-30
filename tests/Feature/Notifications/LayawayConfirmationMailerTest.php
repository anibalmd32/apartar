<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Actions\Layaway\ActivateLayawayAction;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Notification\LayawayConfirmationMailer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Notifications\LayawayConfirmationNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class LayawayConfirmationMailerTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-27 10:00:00', 'America/Caracas'));
        Notification::fake();
        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente', 'timezone' => 'America/Caracas']));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_pending_layaway_asks_for_the_down_payment_before_its_expiry(): void
    {
        $layaway = $this->layawayWithEmail(LayawayStatus::PendingDownPayment, '2026-09-30 10:00:00');

        $this->send($layaway);

        Notification::assertSentOnDemand(LayawayConfirmationNotification::class, fn (LayawayConfirmationNotification $mail, array $channels, object $notifiable): bool => $mail->code === $layaway->code
            && $mail->businessName === 'Ferretería Oriente'
            && $mail->requiresDownPayment
            && ! $mail->downPaymentPaid
            && str_starts_with($mail->expiresAt, '30 de septiembre a las 10:00')
            && $notifiable->routes['mail'] === 'maria@ejemplo.test');
    }

    public function test_an_active_layaway_says_the_down_payment_is_paid_and_names_the_next_installment(): void
    {
        $layaway = $this->layawayWithEmail(LayawayStatus::PendingDownPayment, null);
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'paid', 'paid_cents' => 3_000]);

        $this->send($layaway);

        Notification::assertSentOnDemand(LayawayConfirmationNotification::class, fn (LayawayConfirmationNotification $mail): bool => $mail->downPaymentPaid
            && $mail->firstInstallment === '$30,00'
            && $mail->firstInstallmentNumber === 2
            && ! $mail->firstInstallmentStarted
            && ! $mail->fullyPaid
            && $mail->expiresAt === '');
    }

    public function test_a_started_installment_is_announced_with_what_is_left_of_it(): void
    {
        $layaway = $this->layawayWithEmail(LayawayStatus::PendingDownPayment, null);
        $this->withinOrganization($this->organization, fn () => app(ActivateLayawayAction::class)->handle($layaway));
        DB::table('installments')->where('layaway_id', $layaway->getKey())->where('number', 1)->update(['status' => 'partially_paid', 'paid_cents' => 1_000]);

        $this->send($layaway);

        Notification::assertSentOnDemand(LayawayConfirmationNotification::class, fn (LayawayConfirmationNotification $mail): bool => $mail->firstInstallment === '$20,00'
            && $mail->firstInstallmentNumber === 1
            && $mail->firstInstallmentStarted);
    }

    public function test_a_layaway_completed_at_creation_is_announced_as_fully_paid(): void
    {
        $layaway = $this->layawayWithEmail(LayawayStatus::Completed, null);

        $this->send($layaway);

        Notification::assertSentOnDemand(LayawayConfirmationNotification::class, fn (LayawayConfirmationNotification $mail): bool => $mail->fullyPaid
            && $mail->firstInstallment === null);
    }

    public function test_without_an_email_nothing_is_sent(): void
    {
        $layaway = $this->layawayIn($this->organization);

        $this->send($layaway);

        Notification::assertNothingSent();
    }

    private function layawayWithEmail(LayawayStatus $status, ?string $expiresAt): Layaway
    {
        $layaway = $this->layawayIn($this->organization, $status);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['email' => 'maria@ejemplo.test']);
        DB::table('layaways')->where('id', $layaway->getKey())->update(['expires_at' => $expiresAt]);

        return $this->withinOrganization($this->organization, fn (): Layaway => Layaway::query()->findOrFail($layaway->getKey()));
    }

    private function send(Layaway $layaway): void
    {
        $this->withinOrganization($this->organization, fn () => app(LayawayConfirmationMailer::class)->send($layaway));
    }
}
