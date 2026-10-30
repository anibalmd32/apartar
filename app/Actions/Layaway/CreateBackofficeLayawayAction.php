<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Customer\ResolveCustomerAction;
use App\Actions\Payment\RegisterManualPaymentAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Exchange\MissingExchangeRateException;
use App\Domain\Exchange\UnconfirmedRateDeviationException;
use App\Domain\Exchange\UnconfirmedStaleRateException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Notification\LayawayConfirmationMailer;
use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\IdempotencyKeyReusedException;
use App\Domain\Payment\PaymentNotApplicableException;
use App\Domain\Payment\UnconfirmedExcessException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\LayawayCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class CreateBackofficeLayawayAction
{
    use LogsSensitiveActivity;

    public const PROCESS = 'apartado creado desde el backoffice';

    private const IDEMPOTENCY_INDEX = 'layaways_organization_id_idempotency_key_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly ResolveCustomerAction $customers,
        private readonly ReserveStockAction $stock,
        private readonly OpenLayawayAction $opening,
        private readonly RegisterManualPaymentAction $payments,
        private readonly LayawayConfirmationMailer $mailer,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ProductNotAvailableException
     * @throws InsufficientStockException
     * @throws PlanLimitReachedException
     * @throws CreationSuspendedException
     * @throws CrossTenantRelationException
     * @throws PaymentNotApplicableException
     * @throws MissingExchangeRateException
     * @throws UnconfirmedExcessException
     * @throws IdempotencyKeyReusedException
     * @throws UnconfirmedStaleRateException
     * @throws UnconfirmedRateDeviationException
     * @throws DuplicateReceiptException
     */
    public function handle(BackofficeLayawayData $data, User $actor): CreatedLayaway
    {
        Gate::forUser($actor)->authorize('create', Layaway::class);

        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $this->replay($existing, $data);
        }

        try {
            return DB::transaction(fn (): CreatedLayaway => $this->transact($data, $actor));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            return $this->replay($this->findByKey($data->idempotencyKey) ?? throw $exception, $data);
        }
    }

    private function transact(BackofficeLayawayData $data, User $actor): CreatedLayaway
    {
        $product = ProductLock::acquire((int) $data->product->getKey());

        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $this->replay($existing, $data);
        }

        $this->assertReservable($product);

        if ($product->stock < $data->quantity) {
            throw InsufficientStockException::for($product, $data->quantity);
        }

        $this->limits->assertCanCreate(UsageMetric::ActiveLayaways);

        $organization = Organization::query()->whereKey($this->context->organizationId())->lockForUpdate()->firstOrFail();

        if ($organization->status !== OrganizationStatus::Active) {
            throw CreationSuspendedException::whileCreating(UsageMetric::ActiveLayaways);
        }

        $customer = $this->customerFor($data, $actor);
        $customerCreated = $customer->wasRecentlyCreated;

        $terms = new LayawayTerms(
            unitPrice: $product->price_cents,
            downPayment: $product->down_payment_cents,
            quantity: $data->quantity,
            installmentsCount: $product->installments_count,
            frequencyDays: $product->frequency_days,
        );

        $layaway = LayawayCode::claim(fn (string $code): Layaway => $this->insert($data, $product, $customer, $terms, $actor, $code));

        $this->stock->handle($product, $data->quantity);
        $this->limits->adjust(UsageMetric::ActiveLayaways, 1);

        $this->opening->handle($layaway);

        $this->audit(
            AuditEvent::LayawayCreated,
            subject: $layaway,
            actor: $actor,
            after: [
                'code' => $layaway->code,
                'product' => $product->name,
                'quantity' => $terms->quantity,
                'total_cents' => $terms->total()->cents,
                'down_payment_cents' => $terms->totalDownPayment()->cents,
                'customer' => (int) $customer->getKey(),
                'customer_created' => $customerCreated,
                'terms_declared_by_business' => $customerCreated && $customer->terms_declared_by_user_id !== null,
                'down_payment_window' => $terms->requiresDownPayment() ? $data->window->describe($this->opening->downPaymentWindowHours()) : null,
                'expires_at' => $layaway->expires_at?->toIso8601String(),
                'status' => $layaway->status->value,
                'origin' => self::PROCESS,
            ],
        );

        ServerTelemetry::record(
            TelemetryEventName::LayawayCreated,
            organizationId: (int) $organization->getKey(),
            subjectId: (int) $layaway->getKey(),
            payload: ['product_id' => (int) $product->getKey(), 'quantity' => $data->quantity, 'source' => 'backoffice'],
            customerId: (int) $customer->getKey(),
        );

        $payment = $data->payment === null ? null : $this->payments->handle(
            $layaway,
            $data->payment,
            $actor,
            notifyCustomer: $this->mailer->emailOf((int) $customer->getKey()) === null,
        );

        DB::afterCommit(fn () => $this->mailer->send($layaway->refresh()));

        return new CreatedLayaway($layaway, $customer, $payment, true);
    }

    private function customerFor(BackofficeLayawayData $data, User $actor): Customer
    {
        if ($data->customer instanceof Customer) {
            if ((int) $data->customer->organization_id !== $this->context->organizationId()) {
                throw CrossTenantRelationException::between(Customer::class, Layaway::class);
            }

            return Customer::query()->whereKey($data->customer->getKey())->lockForUpdate()->firstOrFail();
        }

        if ($data->contact === null || $data->consent === null) {
            throw new LogicException('BackofficeLayawayData garantiza ficha o contacto con su consentimiento.');
        }

        return $this->customers->declaredBy($data->contact, $data->consent, $actor);
    }

    private function assertReservable(Product $product): void
    {
        $live = Product::query()->published()->whereKey($product->getKey())->exists();

        if (! $live) {
            throw ProductNotAvailableException::for($product);
        }
    }

    private function insert(BackofficeLayawayData $data, Product $product, Customer $customer, LayawayTerms $terms, User $actor, string $code): Layaway
    {
        $total = $terms->total();

        $layaway = new Layaway;

        $layaway->forceFill([
            'code' => $code,
            'idempotency_key' => $data->idempotencyKey,
            'customer_id' => $customer->getKey(),
            'product_id' => $product->getKey(),
            'catalog_id' => null,
            'created_by_user_id' => $actor->getKey(),

            'quantity' => $terms->quantity,
            'unit_price_cents' => $terms->unitPrice,
            'down_payment_cents' => $terms->downPayment,
            'installments_count' => $terms->installmentsCount,
            'frequency_days' => $terms->frequencyDays,
            'product_name_snapshot' => $product->name,
            'product_sku_snapshot' => $product->sku,
            'total_cents' => $total,

            'paid_cents' => 0,
            'remaining_cents' => $total,

            'status' => LayawayStatus::PendingDownPayment,
            'expires_at' => $this->opening->expiresAt($terms, $data->window),
        ])->save();

        return $layaway;
    }

    private function replay(Layaway $layaway, BackofficeLayawayData $data): CreatedLayaway
    {
        $payment = $data->payment === null ? null : Payment::query()
            ->where('layaway_id', $layaway->getKey())
            ->where('idempotency_key', $data->payment->idempotencyKey)
            ->first();

        $customer = Customer::query()->findOrFail($layaway->customer_id);

        return new CreatedLayaway($layaway, $customer, $payment, false);
    }

    private function findByKey(string $key): ?Layaway
    {
        return Layaway::query()->where('idempotency_key', $key)->first();
    }
}
