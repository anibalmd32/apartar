<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Telemetry\IngestBatchData;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelemetryBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

final class TelemetryController extends Controller
{
    public const MAX_BODY_BYTES = 65536;

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->noContent(413);
        }

        $batch = IngestBatchData::fromBody(json_decode($body, true), $request->ip(), $request->userAgent());

        if ($batch === null) {
            return response()->noContent(422);
        }

        try {
            ProcessTelemetryBatch::dispatch($batch);
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->noContent();
    }
}
