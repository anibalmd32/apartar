<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Notification\NotifiableResolver;
use App\Domain\Notification\NotificationType;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class DispatchDueRemindersAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Settings $settings,
        private readonly LimitEnforcer $limits,
        private readonly NotifiableResolver $resolver,
        private readonly CustomerNotices $notices,
    ) {}

    /**
     * @return array{sent: int, unreachable: int}
     */
    public function handle(Organization $organization, CarbonImmutable $now, bool $installments): array
    {
        return $this->context->runFor($organization, fn (): array => DB::transaction(function () use ($organization, $now, $installments): array {
            $result = ['sent' => 0, 'unreachable' => 0];

            if ($installments) {
                $result = $this->installments($organization, $now);
            }

            $result['sent'] += $this->downPayments($now);

            return $result;
        }));
    }

    /**
     * @return array{sent: int, unreachable: int}
     */
    private function installments(Organization $organization, CarbonImmutable $now): array
    {
        $today = $now->setTimezone($organization->timezone)->startOfDay();
        $offsets = array_map('intval', (array) $this->settings->get(SettingKey::ReminderOffsetsDays));
        $byCustomer = [];
        $unreachable = [];

        foreach ($offsets as $offset) {
            $rows = Installment::query()
                ->join('layaways', 'layaways.id', '=', 'installments.layaway_id')
                ->whereIn('layaways.status', [LayawayStatus::Active->value, LayawayStatus::Overdue->value])
                ->whereColumn('installments.paid_cents', '<', 'installments.amount_cents')
                ->whereDate('installments.due_date', $today->subDays($offset)->toDateString())
                ->select('installments.*')
                ->get();

            foreach ($rows as $installment) {
                $layaway = Layaway::query()->findOrFail($installment->layaway_id);
                $customer = Customer::query()->findOrFail($layaway->customer_id);

                if (! $this->reachable($customer)) {
                    $unreachable[$customer->getKey()] = true;

                    continue;
                }

                if ($this->record($customer, NotificationType::InstallmentReminder, sprintf('installment:%d:%d', $installment->getKey(), $offset), $now, installmentId: (int) $installment->getKey(), layawayId: (int) $layaway->getKey())) {
                    $byCustomer[(int) $customer->getKey()]['customer'] = $customer;
                    $byCustomer[(int) $customer->getKey()]['items'][] = ['installment' => $installment, 'layaway' => $layaway, 'offset' => $offset];
                }
            }
        }

        foreach ($byCustomer as $group) {
            $this->notices->installmentReminder($group['customer'], $group['items']);
        }

        return ['sent' => count($byCustomer), 'unreachable' => count($unreachable)];
    }

    private function downPayments(CarbonImmutable $now): int
    {
        $sent = 0;

        $layaways = Layaway::query()
            ->where('status', LayawayStatus::PendingDownPayment)
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addHours(12))
            ->get();

        foreach ($layaways as $layaway) {
            $customer = Customer::query()->find($layaway->customer_id);

            if ($customer instanceof Customer && $this->reachable($customer)
                && $this->record($customer, NotificationType::DownPaymentReminder, sprintf('layaway:%d:down_payment', $layaway->getKey()), $now, layawayId: (int) $layaway->getKey())) {
                $this->notices->downPaymentReminder($layaway);
                $sent++;
            }
        }

        return $sent;
    }

    private function reachable(Customer $customer): bool
    {
        $recipient = $this->resolver->for($customer);

        return filled($recipient->routeNotificationFor('mail'))
            || ($this->limits->hasFeature(PlanFeature::WebPush) && $recipient->pushSubscriptions()->exists());
    }

    private function record(Customer $customer, NotificationType $type, string $key, CarbonImmutable $now, ?int $installmentId = null, ?int $layawayId = null): bool
    {
        $recipient = $this->resolver->for($customer);

        return DB::table('notification_dispatches')->insertOrIgnore([
            'organization_id' => $customer->organization_id,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->getKey(),
            'installment_id' => $installmentId,
            'layaway_id' => $layawayId,
            'type' => $type->value,
            'channel' => 'any',
            'dedupe_key' => $key,
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
