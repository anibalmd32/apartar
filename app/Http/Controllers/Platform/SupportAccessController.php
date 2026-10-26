<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\SupportAccessGrant;
use App\Queries\Platform\SupportGrants;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SupportAccessController extends Controller
{
    public function index(Request $request, SupportGrants $grants): Response
    {
        $onlyActive = $request->query('estado', 'vigentes') !== 'todos';
        $page = max(1, $request->integer('pagina', 1));
        $listing = $grants->listing($onlyActive, $page);

        return Inertia::render('platform/support/Index', [
            'grants' => array_map(fn (SupportAccessGrant $grant): array => [
                'id' => $grant->getKey(),
                'organization' => ['name' => $grant->organization->name ?? '—', 'slug' => $grant->organization->slug ?? null],
                'operator' => $grant->operator->name ?? '—',
                'reason' => $grant->reason,
                'grantedAt' => $grant->granted_at->toIso8601String(),
                'expiresAt' => $grant->expires_at->toIso8601String(),
                'revokedAt' => $grant->revoked_at?->toIso8601String(),
                'active' => $grant->isActive(),
            ], $listing['rows']),
            'filter' => $onlyActive ? 'vigentes' : 'todos',
            'page' => $page,
            'hasMore' => $listing['hasMore'],
        ]);
    }
}
