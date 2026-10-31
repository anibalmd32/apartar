<?php

declare(strict_types=1);

use App\Actions\Subscription\ApplyDowngradeAction;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\ApplyScheduledDowngrades;
use App\Jobs\DetectOverdueLayawaysJob;
use App\Jobs\ExpireLayawaysJob;
use App\Jobs\FetchExchangeRate;
use App\Jobs\IssueRenewalInvoices;
use App\Jobs\ProcessSubscriptionDunning;
use App\Jobs\PurgeExpiredExports;
use App\Jobs\PurgeStalePushSubscriptions;
use App\Jobs\RecalculateUsage;
use App\Jobs\ReconcileLayaways;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new FetchExchangeRate)
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->name('exchange-rate:fetch');

Schedule::job(new ExpireLayawaysJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('layaways:expire');

Schedule::job(new DetectOverdueLayawaysJob)
    ->hourly()
    ->withoutOverlapping()
    ->name('layaways:detect-overdue');

Schedule::job(new ReconcileLayaways)
    ->dailyAt('05:30')
    ->withoutOverlapping()
    ->name('layaways:reconcile');

Schedule::job(new RecalculateUsage)
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->name('subscriptions:recalculate-usage');

Schedule::job(new RecalculateUsage(metric: UsageMetric::Catalogs))
    ->hourlyAt(15)
    ->withoutOverlapping()
    ->name('subscriptions:recalculate-usage:catalogs');

Schedule::job(new IssueRenewalInvoices)
    ->dailyAt('06:00')
    ->withoutOverlapping()
    ->name('subscriptions:issue-renewal-invoices');

Schedule::job(new ApplyScheduledDowngrades)
    ->hourlyAt(5)
    ->withoutOverlapping()
    ->name(ApplyDowngradeAction::PROCESS);

Schedule::job(new ProcessSubscriptionDunning)
    ->dailyAt('06:30')
    ->withoutOverlapping()
    ->name(ProcessSubscriptionDunning::PROCESS);

Schedule::job(new PurgeExpiredExports)
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->name('exports:purge-expired');

Schedule::command('chat:recount')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->name('chat:recount');

Schedule::job(new PurgeStalePushSubscriptions)
    ->weeklyOn(0, '03:30')
    ->withoutOverlapping()
    ->name('push:purge-stale');

Schedule::command('notifications:dispatch-reminders')
    ->hourly()
    ->withoutOverlapping()
    ->name('notifications:dispatch-reminders');

Schedule::command('telemetry:purge')
    ->dailyAt('03:45')
    ->withoutOverlapping()
    ->name('telemetry:purge');

Schedule::command('telescope:prune', ['--hours' => 72])
    ->dailyAt('04:15')
    ->withoutOverlapping()
    ->name('telescope:prune');
