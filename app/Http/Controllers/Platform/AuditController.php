<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Queries\Platform\PlatformActivityFilters;
use App\Queries\Platform\PlatformActivityLog;
use App\Support\Auditing\PlatformAudit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AuditController extends Controller
{
    public function index(Request $request, PlatformActivityLog $log): Response
    {
        $filters = PlatformActivityFilters::fromRequest($request);
        $page = $log->list($filters);

        return Inertia::render('platform/audit/Index', [
            'entries' => $page['rows'],
            'hasMore' => $page['hasMore'],
            'page' => $filters->page,
            'filters' => $filters->toArray(),
            'operators' => PlatformUser::query()->orderBy('name')->get(['id', 'name'])->map(fn (PlatformUser $operator): array => ['id' => $operator->getKey(), 'name' => $operator->name])->all(),
            'actions' => collect(PlatformAudit::LABELS)->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])->values()->all(),
        ]);
    }
}
