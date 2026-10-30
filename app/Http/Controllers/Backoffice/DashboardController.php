<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Domain\Collections\CollectionPreset;
use App\Domain\Dashboard\DashboardLayout;
use App\Domain\Money\Money;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\User;
use App\Queries\DashboardMetricsQuery;
use App\Queries\OldestPendingReceiptsQuery;
use App\Queries\SuspensionNoticeQuery;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    use ProjectsMoney;

    public function __construct(
        private readonly TenantContext $context,
        private readonly DashboardMetricsQuery $metrics,
        private readonly OldestPendingReceiptsQuery $oldestReceipts,
        private readonly SuspensionNoticeQuery $suspension,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $metrics = $this->metrics->compute($this->context->organization());
        $collected = $metrics['collectedThisMonthCents'] ?? null;

        return Inertia::render('backoffice/Dashboard', [
            'layout' => DashboardLayout::of($user instanceof User ? $user : null)->toArray(),
            'metrics' => [
                ...$metrics,
                'collectedThisMonth' => is_int($collected) ? $this->money(new Money($collected)) : null,
                'warnings' => $this->suspendedFirst(is_array($metrics['warnings'] ?? null) ? $metrics['warnings'] : []),
            ],
            'pendingReceipts' => $user instanceof User && $user->role->can(OrganizationCapability::Receipts)
                ? $this->oldestReceipts->get(CarbonImmutable::now())
                : null,
            'longOverdueDays' => app(Settings::class)->longOverdueDays(),
            'suspension' => $this->suspension->for($this->context->organization()),
            'links' => [
                'receipts' => route('receipts.index', ['status' => 'pending_review']),
                'invoices' => $user instanceof User && $user->role->canManageBilling() ? route('subscription.invoices.index') : null,
                'customersOverdue' => route('customers.index', ['overdue' => '1']),
                'longOverdue' => route('collections.index', ['preset' => CollectionPreset::LongOverdue->value]),
                'completedNotDelivered' => route('collections.index', ['preset' => 'completed_not_delivered']),
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $warnings
     * @return list<array<string, mixed>>
     */
    private function suspendedFirst(array $warnings): array
    {
        usort($warnings, fn (array $a, array $b): int => ($b['kind'] === 'invoice_overdue') <=> ($a['kind'] === 'invoice_overdue'));

        return $warnings;
    }
}
