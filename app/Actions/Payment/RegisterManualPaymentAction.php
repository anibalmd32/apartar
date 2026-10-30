<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Exchange\ExchangeRateService;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\RateDeviation;
use App\Domain\Exchange\StaleRate;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Money\Money;
use App\Domain\Notification\CustomerNotices;
use App\Domain\Payment\AppliedPayment;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\DuplicateReceipts;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentApplier;
use App\Domain\Payment\PaymentAttachment;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\CompressReceiptImage;
use App\Models\Bank;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class RegisterManualPaymentAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly PaymentApplier $applier,
        private readonly ExchangeRateService $rates,
        private readonly TenantContext $context,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws CrossTenantRelationException
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws IdempotencyKeyReusedException
     * @throws UnconfirmedStaleRateException
     * @throws UnconfirmedRateDeviationException
     * @throws DuplicateReceiptException
     */
    public function handle(Layaway $layaway, ManualPaymentData $data, User $actor, bool $notifyCustomer = true): Payment
    {
        Gate::forUser($actor)->authorize('manage', $layaway);

        $account = $data->paymentAccount;

        if ($account instanceof PaymentAccount && (int) $account->organization_id !== (int) $layaway->organization_id) {
            throw CrossTenantRelationException::between(PaymentAccount::class, Layaway::class);
        }

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        $stored = null;

        try {
            return DB::transaction(function () use ($layaway, $data, $actor, $notifyCustomer, &$stored): Payment {
                DB::afterCommit(function () use (&$stored): void {
                    $stored = null;
                });

                return $this->register($layaway, $data, $actor, $notifyCustomer, $stored);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $this->discard($stored);

            $criterion = DuplicateReceipts::fromViolation($exception, (new Payment)->getTable());

            if ($criterion !== null) {
                throw DuplicateReceiptException::recordedByBusiness($criterion, $exception);
            }

            return $this->alreadyRegistered($layaway, $data) ?? throw $exception;
        } catch (Throwable $exception) {
            $this->discard($stored);

            throw $exception;
        }
    }

    private function register(Layaway $layaway, ManualPaymentData $data, User $actor, bool $notifyCustomer, ?string &$stored): Payment
    {
        if ($data->reference !== null || $data->file instanceof UploadedFile) {
            Organization::query()->whereKey($layaway->organization_id)->lockForUpdate()->firstOrFail();
        }

        Layaway::query()->whereKey($layaway->getKey())->lockForUpdate()->firstOrFail();

        $existing = $this->alreadyRegistered($layaway, $data);

        if ($existing instanceof Payment) {
            return $existing;
        }

        $hash = $data->file instanceof UploadedFile ? ReceiptFile::fingerprint($data->file) : null;
        $criterion = DuplicateReceipts::inOrganization($hash, Bank::canonicalName($data->bank), $data->reference);

        if ($criterion !== null) {
            throw DuplicateReceiptException::recordedByBusiness($criterion);
        }

        $rate = $data->manualRate() ?? $this->rates->current($this->context->organization());
        StaleRate::in($rate, $data->currency)?->assertConfirmedBy($data->confirmedStaleRate);
        RateDeviation::in($rate, $this->rates->current($this->context->organization()), $data->currency)?->assertConfirmedBy($data->confirmedRateDeviation);

        $attachment = $data->file instanceof UploadedFile && $hash !== null
            ? $this->store($data->file, $hash, (int) $layaway->organization_id, $stored)
            : null;

        $applied = $this->applier->apply(
            $layaway,
            $data->money(),
            $rate,
            $data->amount,
            $data->currency,
            null,
            $actor,
            $data->note,
            $data->idempotencyKey,
            $data->origin($attachment),
        );

        $excess = $applied->plan->unallocatedCents;

        if ($excess > 0 && $excess !== $data->confirmedExcessCents) {
            throw new UnconfirmedExcessException(new Money($excess));
        }

        $this->audit(
            AuditEvent::PaymentRecorded,
            subject: $applied->payment,
            actor: $actor,
            after: $this->detailOf($applied, $data),
        );

        if ($notifyCustomer) {
            $credited = $applied->payment->amount_cents_usd;
            DB::afterCommit(fn () => app(CustomerNotices::class)->paymentCredited($layaway->refresh(), $credited));
        }

        if ($attachment instanceof PaymentAttachment) {
            $this->processAttachmentAfterCommit($applied->payment, $attachment);
        }

        return $applied->payment;
    }

    /**
     * @throws IdempotencyKeyReusedException
     */
    private function alreadyRegistered(Layaway $layaway, ManualPaymentData $data): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $data->idempotencyKey)->first();

        if (! $payment instanceof Payment) {
            return null;
        }

        $same = (int) $payment->layaway_id === (int) $layaway->getKey()
            && $payment->declared_currency === $data->currency
            && bccomp((string) $payment->declared_amount, $data->amount, 2) === 0
            && $payment->bank === Bank::canonicalName($data->bank)
            && $payment->reference === $data->reference
            && $payment->file_hash === ($data->file instanceof UploadedFile ? ReceiptFile::fingerprint($data->file) : null);

        return $same ? $payment : throw new IdempotencyKeyReusedException;
    }

    /**
     * @param-out string $stored
     *
     * @throws RuntimeException
     */
    private function store(UploadedFile $file, string $hash, int $organizationId, ?string &$stored): PaymentAttachment
    {
        [$mime, $extension] = ReceiptFile::typeOf($file);
        $path = ReceiptFile::pathFor($organizationId, $extension);

        if (Storage::disk(Receipt::DISK)->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException(sprintf('El disco «%s» no guardó el adjunto del pago en %s.', Receipt::DISK, $path));
        }

        $stored = $path;

        return new PaymentAttachment($path, $hash, $mime, (int) $file->getSize());
    }

    private function discard(?string $path): void
    {
        if ($path === null) {
            return;
        }

        try {
            if (Payment::query()->where('file_path', $path)->exists()) {
                return;
            }

            Storage::disk(Receipt::DISK)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function processAttachmentAfterCommit(Payment $payment, PaymentAttachment $attachment): void
    {
        $organization = $this->context->organization();
        $paymentId = (int) $payment->getKey();
        $compresses = CompressReceiptImage::compresses($attachment->mime);

        DB::afterCommit(function () use ($organization, $paymentId, $compresses): void {
            try {
                if ($compresses) {
                    $this->context->runFor($organization, function () use ($organization, $paymentId): void {
                        Bus::dispatch(CompressReceiptImage::ofPayment($paymentId, (int) $organization->getKey()));
                    });
                }

                $this->usage->refresh($organization, UsageMetric::StorageMb);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(AppliedPayment $applied, ManualPaymentData $data): array
    {
        $numbers = Installment::query()
            ->whereKey(array_column($applied->plan->allocations, 'installmentId'))
            ->pluck('number', 'id')
            ->all();

        return [
            'layaway' => $applied->payment->layaway_id,
            'amount_cents_usd' => $applied->payment->amount_cents_usd->cents,
            'paid' => $data->amount.' '.$data->currency->value,
            'payment_method' => $data->method->value,
            'payment_account' => $data->paymentAccount?->getKey(),
            'paid_on' => $data->paidAt->toDateString(),
            'rate_applied' => $applied->payment->rate_applied,
            'rate_source' => $applied->payment->rate_source->value,
            'rate_published_at' => $applied->payment->rate_published_at?->toIso8601String(),
            'down_payment_cents' => $applied->downPaymentCents,
            'allocations' => array_map(fn (array $allocation): array => [
                'installment' => $numbers[$allocation['installmentId']] ?? null,
                'cents' => $allocation['cents'],
            ], $applied->plan->allocations),
            'unallocated_cents' => $applied->plan->unallocatedCents,
            'layaway_status' => $applied->statusBefore->value.' → '.$applied->statusAfter->value,
            'note' => $data->note,
        ] + ($data->manualRate() !== null ? ['rate_override' => $data->rateOverride] : [])
            + ($data->reference !== null ? ['bank' => $applied->payment->bank, 'reference' => $data->reference] : [])
            + ($applied->payment->hasAttachment() ? ['attachment' => $applied->payment->file_mime] : []);
    }
}
