<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\CustomerHasLiveLayawaysException;
use App\Domain\Layaway\LayawayStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DeleteCustomerAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'ficha del cliente en el backoffice';

    /**
     * @throws CustomerHasLiveLayawaysException
     */
    public function handle(Customer $customer, User $actor): bool
    {
        Gate::forUser($actor)->authorize('delete', $customer);

        return DB::transaction(function () use ($customer, $actor): bool {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->getKey())->lockForUpdate()->firstOrFail();
            $live = self::liveLayawaysOf($locked);

            if ($live > 0) {
                throw new CustomerHasLiveLayawaysException($live);
            }

            $keepsHistory = Layaway::query()->where('customer_id', $locked->getKey())->exists()
                || Receipt::query()->where('customer_id', $locked->getKey())->exists();

            $this->audit(
                AuditEvent::CustomerDeleted,
                subject: $locked,
                actor: $actor,
                before: ['full_name' => $locked->full_name, 'document_number' => $locked->document_number, 'phone' => $locked->phone],
                after: ['history_kept' => $keepsHistory],
                process: self::PROCESS,
            );

            $keepsHistory ? $locked->delete() : $locked->forceDelete();

            return $keepsHistory;
        });
    }

    public static function liveLayawaysOf(Customer $customer): int
    {
        return Layaway::query()
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', array_map(fn (LayawayStatus $status): string => $status->value, [LayawayStatus::PendingDownPayment, LayawayStatus::Active, LayawayStatus::Overdue]))
            ->count();
    }
}
