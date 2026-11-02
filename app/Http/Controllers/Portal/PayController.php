<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\LayawaySummaryResource;
use App\Http\Resources\NextPaymentResource;
use App\Models\Layaway;
use App\Queries\BusinessBalance;
use App\Queries\CustomerBalanceQuery;
use App\Support\LayawayCode;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class PayController extends Controller
{
    public function __invoke(Request $request, PortalContext $portal, CustomerBalanceQuery $balances, TenantContext $context): Response|RedirectResponse
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

        $chosen = $this->chosen($rows, $request->query(AccessController::CODE_PARAMETER));

        if ($chosen !== null) {
            return redirect()->to($chosen['payUrl']);
        }

        usort($rows, fn (array $a, array $b): int => [$b['createdAt'], $b['layaway']['code']] <=> [$a['createdAt'], $a['layaway']['code']]);

        return Inertia::render('portal/Pay', [
            'layaways' => array_map(fn (array $row): array => [...$row['layaway'], 'businessName' => $row['businessName'], 'payUrl' => $row['payUrl']], $rows),
            'dashboardUrl' => route('portal.dashboard'),
        ]);
    }

    /**
     * @param  list<array{createdAt: int, businessName: string, payUrl: string, layaway: array<string, mixed>}>  $rows
     * @return array{createdAt: int, businessName: string, payUrl: string, layaway: array<string, mixed>}|null
     */
    private function chosen(array $rows, mixed $code): ?array
    {
        if (count($rows) === 1) {
            return $rows[0];
        }

        if (! is_string($code) || $code === '') {
            return null;
        }

        $code = LayawayCode::normalize($code);

        foreach ($rows as $row) {
            if ($row['layaway']['code'] === $code) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return list<array{createdAt: int, businessName: string, payUrl: string, layaway: array<string, mixed>}>
     */
    private function rowsOf(BusinessBalance $block, Request $request): array
    {
        $timezone = new DateTimeZone($block->organization->timezone);

        return array_values(array_map(fn (Layaway $layaway): array => [
            'createdAt' => (int) $layaway->created_at?->getTimestamp(),
            'businessName' => $block->organization->name,
            'payUrl' => route('portal.receipts.create', ['code' => $layaway->code]),
            'layaway' => [
                ...(new LayawaySummaryResource($layaway))->resolve($request),
                'suggestion' => $this->suggestionOf($block, $layaway, $timezone, $request),
            ],
        ], array_filter($block->active, fn (Layaway $layaway): bool => $layaway->status->acceptsPayments())));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function suggestionOf(BusinessBalance $block, Layaway $layaway, DateTimeZone $timezone, Request $request): ?array
    {
        $next = $block->suggestionFor($layaway);
        $review = $block->reviewFor($layaway);

        return $next === null || $review === null ? null : (new NextPaymentResource($next, $review, $timezone))->resolve($request);
    }
}
