<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Queries\Platform\SystemHealthQuery;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RecordScheduledRun
{
    public function handle(ScheduledTaskFinished $event): void
    {
        $name = $event->task->description;

        if (! is_string($name) || $name === '') {
            return;
        }

        try {
            Cache::forever(SystemHealthQuery::LAST_RUN_PREFIX.$name, now()->toIso8601String());
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
