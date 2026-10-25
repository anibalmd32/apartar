<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Export\ExportRequestData;
use App\Actions\Export\RequestExportAction;
use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController extends Controller
{
    public function index(Request $request, TenantContext $context, LimitEnforcer $limits, RequestExportAction $permissions): Response
    {
        $timezone = $context->organization()->timezone;
        /** @var User $viewer */
        $viewer = $request->user();
        $visible = array_values(array_filter(ExportType::cases(), fn (ExportType $type): bool => $permissions->allows($type, $viewer)));

        $exports = ExportJob::query()->whereIn('type', $visible)->latest('id')->limit(50)->get();

        return Inertia::render('backoffice/exports/Index', [
            'exports' => $exports->map(fn (ExportJob $export): array => $this->row($export, $timezone))->values()->all(),
            'canExport' => $limits->hasFeature(PlanFeature::ExcelExport),
            'types' => array_map(fn (ExportType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'allowed' => in_array($type, $visible, true),
            ], ExportType::cases()),
        ]);
    }

    public function store(Request $request, RequestExportAction $action): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::enum(ExportType::class)],
            'filters' => ['sometimes', 'array'],
        ], [
            'type.required' => 'Elige qué exportar.',
            'type.enum' => 'Ese tipo de exportación no existe.',
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];

        $export = $action->handle(ExportRequestData::of(ExportType::from((string) $validated['type']), $filters), $actor);
        $reused = ! $export->wasRecentlyCreated;

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['id' => $export->getKey(), 'status' => $export->status->value, 'reused' => $reused], 202);
        }

        return to_route('exports.index')->with('status', $reused
            ? sprintf('Ya se está generando una exportación de %s, con los filtros con que se pidió. Cuando termine, podrás pedir la tuya.', mb_strtolower($export->type->label()))
            : 'Estamos generando tu archivo. Te avisamos por correo cuando esté listo.');
    }

    public function download(Request $request, string $export, RequestExportAction $permissions): StreamedResponse
    {
        $found = ctype_digit($export) ? ExportJob::query()->find((int) $export) : null;
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($found instanceof ExportJob && $permissions->allows($found->type, $viewer), 403, 'Esta exportación no es de tu negocio.');

        $gone = $found->status === ExportStatus::Expired
            || $found->expires_at?->isPast() === true
            || ($found->status->isDownloadable() && ($found->file_path === null || ! Storage::disk(ExportJob::DISK)->exists($found->file_path)));
        abort_if($gone, 410, 'Este archivo se borró a los siete días. Pide la exportación otra vez.');
        abort_unless($found->status->isDownloadable() && $found->file_path !== null, 404, 'Esta exportación todavía no tiene archivo.');

        return Storage::disk(ExportJob::DISK)->download($found->file_path, $found->downloadName());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(ExportJob $export, string $timezone): array
    {
        return [
            'id' => $export->getKey(),
            'type' => $export->type->value,
            'typeLabel' => $export->type->label(),
            'status' => $export->status->value,
            'statusLabel' => $export->status->label(),
            'requestedAt' => $export->created_at->setTimezone($timezone)->format('d/m/Y H:i'),
            'requestedIso' => $export->created_at->toIso8601String(),
            'expiresIso' => $export->expires_at?->toIso8601String(),
            'retryFilters' => $export->status === ExportStatus::Failed ? (object) $export->filters : null,
            'waitingMinutes' => $export->status->isInProgress() ? (int) $export->created_at->diffInMinutes(CarbonImmutable::now()) : null,
            'rowCount' => $export->row_count,
            'fileSize' => $export->file_size,
            'errorMessage' => $export->error_message,
            'expiresAt' => $export->expires_at?->setTimezone($timezone)->format('d/m/Y'),
            'downloadUrl' => $export->status->isDownloadable() && $export->expires_at?->isFuture() !== false ? $export->downloadUrl() : null,
        ];
    }
}
