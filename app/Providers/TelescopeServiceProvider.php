<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\MonitorAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

final class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        Telescope::hideRequestParameters(['_token', 'token', 'password', 'password_confirmation', 'current_password', 'code', 'document', 'idempotencyKey']);

        Telescope::hideRequestHeaders(['cookie', 'set-cookie', 'x-csrf-token', 'x-xsrf-token', 'authorization']);

        Telescope::hideResponseParameters(['token', 'auth']);

        Telescope::filterBatch(fn (Collection $entries): bool => $entries->contains(fn (IncomingEntry $entry): bool => $entry->isReportableException()
            || $entry->isFailedRequest()
            || $entry->isFailedJob()
            || $entry->isScheduledTask()
            || $entry->isSlowQuery()
            || $entry->hasMonitoredTag()) || self::sampled());
    }

    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(fn (Request $request): bool => MonitorAccess::granted($request));
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (mixed $user = null): bool => MonitorAccess::granted(request()));
    }

    private static function sampled(): bool
    {
        $percent = max(0, min(100, (int) config('monitor.telescope_sample_percent', 10)));

        return $percent > 0 && random_int(1, 100) <= $percent;
    }
}
