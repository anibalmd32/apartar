<?php

declare(strict_types=1);

namespace App\Actions\Subscription;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Subscription\InvoiceKind;
use App\Domain\Subscription\InvoiceNotices;
use App\Domain\Subscription\InvoiceNoticeType;
use App\Domain\Subscription\InvoiceStatus;
use App\Domain\Subscription\OrganizationDocumentRequiredException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class IssueInvoiceAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoiceNotices $notices,
    ) {}

    /**
     * @throws LogicException
     * @throws OrganizationDocumentRequiredException
     */
    public function handle(
        Subscription $subscription,
        Plan $plan,
        InvoiceKind $kind,
        CarbonImmutable $periodStart,
        CarbonImmutable $periodEnd,
        CarbonImmutable $dueDate,
        ?Model $actor = null,
    ): SubscriptionInvoice {
        if ($plan->price_cents <= 0) {
            throw new LogicException(sprintf('El plan %s no tiene precio y no se factura.', $plan->code));
        }

        $organization = $this->context->organization();

        if ((int) $organization->getKey() !== $subscription->organization_id) {
            throw new LogicException('La factura se emite en la organización de su suscripción.');
        }

        return DB::transaction(function () use ($subscription, $plan, $kind, $periodStart, $periodEnd, $dueDate, $actor, $organization): SubscriptionInvoice {
            self::assertCanBeInvoiced($organization->document);

            $invoice = new SubscriptionInvoice;
            $invoice->forceFill([
                'organization_id' => $subscription->organization_id,
                'plan_id' => $plan->getKey(),
                'kind' => $kind,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount_cents' => $plan->price_cents,
                'paid_cents' => 0,
                'currency' => 'USD',
                'status' => InvoiceStatus::Pending,
                'due_date' => $dueDate->toDateString(),
                'issued_at' => CarbonImmutable::now(),
            ])->save();

            $this->audit(
                AuditEvent::SubscriptionInvoiceIssued,
                subject: $invoice,
                actor: $actor,
                after: [
                    'plan' => $plan->code,
                    'kind' => $kind->value,
                    'amount_cents' => $plan->price_cents,
                    'period_start' => $periodStart->toIso8601String(),
                    'period_end' => $periodEnd->toIso8601String(),
                    'due_date' => $dueDate->toDateString(),
                ],
                process: $actor === null ? 'subscriptions:issue-renewal-invoices' : null,
            );

            DB::afterCommit(function () use ($invoice): void {
                try {
                    $this->notices->sendOnce($invoice, InvoiceNoticeType::Issued);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

            return $invoice;
        });
    }

    /**
     * @throws OrganizationDocumentRequiredException
     */
    public static function assertCanBeInvoiced(?string $document): void
    {
        if (($document === null || trim($document) === '') && SubscriptionInvoice::query()->doesntExist()) {
            throw new OrganizationDocumentRequiredException;
        }
    }
}
