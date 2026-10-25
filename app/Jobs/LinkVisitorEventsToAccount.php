<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Middleware\AssignVisitorId;
use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LinkVisitorEventsToAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(
        public readonly int $accountId,
        public readonly string $visitorId,
    ) {
        $this->onQueue(ProcessTelemetryBatch::QUEUE);
    }

    public static function dispatchFor(Request $request, Account $account): void
    {
        $visitorId = AssignVisitorId::of($request);

        if ($visitorId === null) {
            return;
        }

        rescue(fn () => self::dispatch((int) $account->getKey(), $visitorId), report: true);
    }

    public function handle(): void
    {
        DB::table('telemetry_events')
            ->where('visitor_id', $this->visitorId)
            ->whereNull('account_id')
            ->where('occurred_at', '>=', now()->subDays(LinkVisitorEventsToCustomer::WINDOW_DAYS))
            ->select('id')
            ->chunkById(LinkVisitorEventsToCustomer::CHUNK, function (Collection $rows): void {
                DB::table('telemetry_events')->whereIn('id', $rows->pluck('id'))->update(['account_id' => $this->accountId]);
            });
    }
}
