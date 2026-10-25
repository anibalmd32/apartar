<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use App\Http\Middleware\AssignVisitorId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ServerTelemetry
{
    public const NO_VISITOR = '00000000-0000-0000-0000-000000000000';

    public const SESSION = 'server';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        TelemetryEventName $name,
        ?int $organizationId = null,
        ?int $catalogId = null,
        ?int $subjectId = null,
        array $payload = [],
        ?int $accountId = null,
        ?int $customerId = null,
    ): void {
        try {
            $row = self::row($name, $organizationId, $catalogId, $subjectId, $payload, $accountId, $customerId);

            DB::afterCommit(static function () use ($row): void {
                try {
                    DB::table('telemetry_events')->insert($row);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function row(
        TelemetryEventName $name,
        ?int $organizationId,
        ?int $catalogId,
        ?int $subjectId,
        array $payload,
        ?int $accountId,
        ?int $customerId,
    ): array {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $now = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
        $rules = $name->payloadRules();

        return [
            'organization_id' => $organizationId,
            'catalog_id' => $catalogId,
            'event_name' => $name->value,
            'visitor_id' => ($request === null ? null : AssignVisitorId::of($request)) ?? self::NO_VISITOR,
            'account_id' => $accountId,
            'customer_id' => $customerId,
            'subject_type' => $subjectId === null ? null : $name->subjectType(),
            'subject_id' => $subjectId,
            'payload' => json_encode((object) array_intersect_key($payload, $rules), JSON_THROW_ON_ERROR),
            'session_id' => self::SESSION,
            'ip_hash' => IpHasher::hash($request?->ip()),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
            'occurred_at' => $now,
            'created_at' => $now,
        ];
    }
}
