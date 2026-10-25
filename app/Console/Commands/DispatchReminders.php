<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Notification\DispatchCatalogDigestAction;
use App\Actions\Notification\DispatchDueRemindersAction;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class DispatchReminders extends Command
{
    protected $signature = 'notifications:dispatch-reminders';

    protected $description = 'Envía los recordatorios de cobro y el digest de novedades que tocan a esta hora';

    public function handle(DispatchDueRemindersAction $reminders, DispatchCatalogDigestAction $digest): int
    {
        $now = CarbonImmutable::now();
        $organizations = Organization::query()->where('status', '!=', OrganizationStatus::Closed)->orderBy('id')->get();
        $sent = 0;

        foreach ($organizations as $organization) {
            $hour = (int) $now->setTimezone($organization->timezone)->format('G');

            try {
                $sent += $reminders->handle($organization, $now, installments: $hour === 8)['sent'];

                if ($hour === DispatchCatalogDigestAction::LOCAL_HOUR) {
                    $digest->handle($organization, $now);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->info(sprintf('%d organizaciones; %d avisos.', $organizations->count(), $sent));

        return self::SUCCESS;
    }
}
