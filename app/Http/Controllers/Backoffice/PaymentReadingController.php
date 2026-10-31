<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Payment\ReadReceiptAction;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\ReceiptReading;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Layaway;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

final class PaymentReadingController extends Controller
{
    public function __invoke(Request $request, TenantContext $context, ReadReceiptAction $read): JsonResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Layaway::class);

        $request->validate([
            'file' => ['required', 'file', 'max:'.(ReceiptFile::maxSizeMb() * 1024)],
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        $today = CarbonImmutable::now($context->organization()->timezone)->toDateString();
        $earliest = min((string) $request->string('desde'), $today);
        $latest = min((string) $request->string('hasta'), $today);
        $file = $request->file('file');

        $reading = $file instanceof UploadedFile ? $read->handle($file, $earliest, $latest) : ReceiptReading::nothing();

        return response()->json(['reading' => $reading->toArray()]);
    }
}
