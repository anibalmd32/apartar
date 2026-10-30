<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Account\LinkAccountAction;
use App\Actions\Account\SubscribeToCatalogAction;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Account\LinkOrigin;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogUnavailableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\NotAcceptingLayawaysException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Telemetry\ServerTelemetry;
use App\Domain\Telemetry\TelemetryEventName;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Account;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PlanLimitRejection;
use App\Models\Product;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\LayawayCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CreateLayawayAction
{
    use LogsSensitiveActivity;

    private const IDEMPOTENCY_INDEX = 'layaways_organization_id_idempotency_key_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly ResolveCustomerAction $customers,
        private readonly ReserveStockAction $stock,
        private readonly SubscribeToCatalogAction $subscribe,
        private readonly LinkAccountAction $link,
        private readonly OpenLayawayAction $opening,
    ) {}

    /**
     * @throws CatalogUnavailableException
     * @throws ProductNotAvailableException
     * @throws InsufficientStockException
     * @throws NotAcceptingLayawaysException
     * @throws TenantAlreadySetException
     */
    public function handle(CreateLayawayData $data): Layaway
    {
        $organization = $this->organizationOf($data->catalog);

        return $this->context->runFor($organization, fn (): Layaway => $this->create($data, $organization));
    }

    private function create(CreateLayawayData $data, Organization $organization): Layaway
    {
        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $existing;
        }

        $this->assertCatalogIsOpen($data->catalog);
        $this->assertProductIsInCatalog($data->catalog, $data->product);

        try {
            return DB::transaction(fn (): Layaway => $this->transact($data, $organization));
        } catch (NotAcceptingLayawaysException $exception) {
            $this->recordLostSale($exception);

            throw $exception;
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            return Layaway::query()->where('idempotency_key', $data->idempotencyKey)->lockForUpdate()->first()
                ?? throw $exception;
        }
    }

    private function transact(CreateLayawayData $data, Organization $organization): Layaway
    {
        $product = ProductLock::acquire((int) $data->product->getKey());

        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $existing;
        }

        ProductNotAvailableException::guard($product);

        if ($product->stock < $data->quantity) {
            throw InsufficientStockException::for($product, $data->quantity);
        }

        $this->assertAcceptingLayaways();

        $current = Organization::query()->whereKey($organization->getKey())->sharedLock()->firstOrFail();

        if ($current->status !== OrganizationStatus::Active) {
            throw CatalogUnavailableException::for($data->catalog);
        }

        $customer = $this->customers->handle($data->contact, $data->consent);

        $terms = new LayawayTerms(
            unitPrice: $product->price_cents,
            downPayment: $product->down_payment_cents,
            quantity: $data->quantity,
            installmentsCount: $product->installments_count,
            frequencyDays: $product->frequency_days,
        );

        $layaway = LayawayCode::claim(fn (string $code): Layaway => $this->insert($data, $product, $customer, $terms, $code));

        $this->stock->handle($product, $data->quantity);
        $this->limits->adjust(UsageMetric::ActiveLayaways, 1);

        $this->opening->handle($layaway);

        if ($data->account !== null) {
            $this->linkOrRecordAnomaly($customer, $data->account);
            $this->subscribe->handle($data->account, $data->catalog);
        }

        ServerTelemetry::record(
            TelemetryEventName::LayawayCreated,
            organizationId: (int) $organization->getKey(),
            catalogId: (int) $data->catalog->getKey(),
            subjectId: (int) $layaway->getKey(),
            payload: ['product_id' => (int) $product->getKey(), 'quantity' => $data->quantity, 'source' => 'catalog'],
            accountId: $data->account === null ? null : (int) $data->account->getKey(),
            customerId: (int) $customer->getKey(),
        );

        return $layaway;
    }

    private function linkOrRecordAnomaly(Customer $customer, Account $account): void
    {
        try {
            $this->link->handle($customer, $account, LinkOrigin::LayawayCreation);
        } catch (CustomerAlreadyLinkedException $conflict) {
            $this->audit(
                AuditEvent::CustomerIdentityConflict,
                subject: $customer,
                after: ['account_link' => $conflict->reason],
                process: 'apartado con cuenta de Google',
            );
        }
    }

    private function insert(CreateLayawayData $data, Product $product, Customer $customer, LayawayTerms $terms, string $code): Layaway
    {
        $total = $terms->total();

        $layaway = new Layaway;

        $layaway->forceFill([
            'code' => $code,
            'idempotency_key' => $data->idempotencyKey,
            'customer_id' => $customer->getKey(),
            'product_id' => $product->getKey(),
            'catalog_id' => $data->catalog->getKey(),

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
            'expires_at' => $this->opening->expiresAt($terms),
        ])->save();

        return $layaway;
    }

    private function organizationOf(Catalog $catalog): Organization
    {
        $organization = Organization::query()->find($catalog->organization_id)
            ?? throw CatalogUnavailableException::for($catalog);

        if ($this->context->hasOrganization() && ! $this->context->organization()->is($organization)) {
            throw TenantAlreadySetException::cannotSwitch($this->context->organizationId(), (int) $organization->getKey());
        }

        return $organization;
    }

    private function assertCatalogIsOpen(Catalog $catalog): void
    {
        $open = match ($catalog->visibility()) {
            CatalogVisibility::Listed, CatalogVisibility::Unlisted => true,
            CatalogVisibility::Draft, CatalogVisibility::Expired => false,
        };

        if (! $open) {
            throw CatalogUnavailableException::for($catalog);
        }
    }

    private function assertProductIsInCatalog(Catalog $catalog, Product $product): void
    {
        $inCatalog = $catalog->products()->published()->whereKey($product->getKey())->exists();

        if (! $inCatalog) {
            throw ProductNotAvailableException::for($product);
        }
    }

    private function assertAcceptingLayaways(): void
    {
        try {
            $this->limits->assertCanCreate(UsageMetric::ActiveLayaways);
        } catch (PlanLimitReachedException|CreationSuspendedException $reason) {
            throw NotAcceptingLayawaysException::because($reason);
        }
    }

    private function recordLostSale(NotAcceptingLayawaysException $exception): void
    {
        $reason = $exception->getPrevious();

        if (! $reason instanceof PlanLimitReachedException) {
            return;
        }

        try {
            PlanLimitRejection::record($reason->metric);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function findByKey(string $key): ?Layaway
    {
        return Layaway::query()->where('idempotency_key', $key)->first();
    }
}
