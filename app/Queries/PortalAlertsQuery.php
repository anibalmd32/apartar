<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Account\PortalContext;
use App\Domain\Payment\LayawayReceipts;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;

final class PortalAlertsQuery
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LayawayReceipts $receipts,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @param  list<BusinessBalance>  $blocks
     * @return list<PortalAlert>
     */
    public function forPortalContext(PortalContext $portal, array $blocks): array
    {
        $now = CarbonImmutable::now();
        $alerts = [];
        $overdueCount = 0;
        $overdueAmounts = [];
        $firstOverdue = null;
        $pendingCount = 0;
        $firstPending = null;

        foreach ($blocks as $block) {
            $organization = $block->organization;
            [$pending, $rejected] = $this->context->runFor($organization, fn (): array => [
                $this->receipts->pendingReviewCounts($block->active),
                $this->receipts->rejectedAwaitingRetry($block->active),
            ]);

            foreach ($block->awaitingDownPayment() as $layaway) {
                $expiresAt = $layaway->expires_at;

                if (($pending[(int) $layaway->getKey()] ?? 0) > 0 || $layaway->paid_cents->cents > 0 || $expiresAt === null || ! $expiresAt->greaterThan($now)) {
                    continue;
                }

                $alerts[] = PortalAlert::downPaymentWindow($organization, $layaway, (int) ceil($now->diffInSeconds($expiresAt) / 60));
            }

            $firstOverdueHere = $block->firstOverdueLayaway();

            if ($firstOverdueHere instanceof Layaway) {
                $overdueCount += $block->overdueInstallments();
                $overdueAmounts[] = ['organization' => $organization, 'amount' => $block->overdueAmount()];
                $firstOverdue ??= $firstOverdueHere;
            }

            foreach ($block->active as $layaway) {
                if (in_array((int) $layaway->getKey(), $rejected, true)) {
                    $alerts[] = PortalAlert::receiptRejected($organization, $layaway);
                }

                $count = $pending[(int) $layaway->getKey()] ?? 0;

                if ($count > 0) {
                    $pendingCount += $count;
                    $firstPending ??= $layaway;
                }
            }

            $soon = $block->dueSoon();

            if ($soon !== null) {
                $alerts[] = PortalAlert::dueSoon($organization, $soon['date'], $soon['amount'], $soon['layaway']);
            }
        }

        if ($firstOverdue instanceof Layaway && $overdueAmounts !== []) {
            $alerts[] = PortalAlert::overdue($overdueCount, $overdueAmounts, $firstOverdue);
        }

        if ($firstPending instanceof Layaway) {
            $alerts[] = PortalAlert::receiptPending($pendingCount, $firstPending);
        }

        $claim = $this->claimInvitation($portal);

        if ($claim instanceof PortalAlert) {
            $alerts[] = $claim;
        }

        return self::sorted($alerts);
    }

    private function claimInvitation(PortalContext $portal): ?PortalAlert
    {
        if (! $portal->isAccount() || $portal->account === null) {
            return null;
        }

        $catalogIds = $portal->account->subscriptions()
            ->orderByDesc('subscribed_at')
            ->orderByDesc('id')
            ->pluck('catalog_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $owners = $this->resolver->organizationsOfCatalogs($catalogIds);

        foreach ($catalogIds as $catalogId) {
            $organization = $owners[$catalogId] ?? null;

            if ($organization instanceof Organization && $portal->customerIn((int) $organization->getKey()) === null) {
                return PortalAlert::claimAvailable($organization);
            }
        }

        return null;
    }

    /**
     * @param  list<PortalAlert>  $alerts
     * @return list<PortalAlert>
     */
    private static function sorted(array $alerts): array
    {
        usort($alerts, fn (PortalAlert $a, PortalAlert $b): int => [$a->kind->priority(), $a->minutesLeft ?? 0, $a->dueDate?->toDateString() ?? '']
            <=> [$b->kind->priority(), $b->minutesLeft ?? 0, $b->dueDate?->toDateString() ?? '']);

        return $alerts;
    }
}
