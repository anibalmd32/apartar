<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\LayawaySummaryResource;
use App\Models\Layaway;
use App\Queries\BusinessBalance;
use App\Queries\CustomerBalanceQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PayController extends Controller
{
    public function __invoke(Request $request, PortalContext $portal, CustomerBalanceQuery $balances, TenantContext $context): Response
    {
        $rows = [];

        foreach ($balances->forPortalContext($portal) as $block) {
            if ($block->businessIsClosed()) {
                continue;
            }

            foreach ($context->runFor($block->organization, fn (): array => $this->rowsOf($block, $request)) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b): int => [$b['createdAt'], $b['layaway']['code']] <=> [$a['createdAt'], $a['layaway']['code']]);

        return Inertia::render('portal/Pay', [
            'layaways' => array_map(fn (array $row): array => [...$row['layaway'], 'businessName' => $row['businessName'], 'payUrl' => $row['payUrl']], $rows),
            'dashboardUrl' => route('portal.dashboard'),
        ]);
    }

    /**
     * @return list<array{createdAt: int, businessName: string, payUrl: string, layaway: array<string, mixed>}>
     */
    private function rowsOf(BusinessBalance $block, Request $request): array
    {
        return array_values(array_map(fn (Layaway $layaway): array => [
            'createdAt' => (int) $layaway->created_at?->getTimestamp(),
            'businessName' => $block->organization->name,
            'payUrl' => route('portal.receipts.create', ['code' => $layaway->code]),
            'layaway' => (new LayawaySummaryResource($layaway))->resolve($request),
        ], array_filter($block->active, fn (Layaway $layaway): bool => $layaway->status->acceptsPayments())));
    }
}
