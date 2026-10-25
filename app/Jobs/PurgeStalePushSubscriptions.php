<?php

declare(strict_types=1);

namespace App\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

final class PurgeStalePushSubscriptions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const STALE_DAYS = 180;

    public function handle(): int
    {
        $limit = CarbonImmutable::now()->subDays(self::STALE_DAYS);

        return DB::table('push_subscriptions')
            ->where(fn ($query) => $query
                ->where('last_used_at', '<', $limit)
                ->orWhere(fn ($never) => $never->whereNull('last_used_at')->where('created_at', '<', $limit)))
            ->delete();
    }
}
