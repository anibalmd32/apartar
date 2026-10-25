<?php

declare(strict_types=1);

namespace App\Domain\Account;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Support\LayawayCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

final readonly class PortalContext
{
    /**
     * @param  list<Customer>  $linked
     */
    public function __construct(
        public ?Customer $scopedCustomer,
        public ?Account $account = null,
        private array $linked = [],
    ) {}

    public static function fromSession(): self
    {
        $account = Auth::guard('account')->user();

        if ($account instanceof Account) {
            return self::forAccount($account);
        }

        $customer = Auth::guard('customer')->user();

        return new self($customer instanceof Customer ? $customer : null);
    }

    public static function forAccount(Account $account): self
    {
        $context = app(TenantContext::class);
        $linked = [];

        foreach (app(TenantResolver::class)->organizationsOfAccount((int) $account->getKey()) as $organization) {
            $found = $context->runFor($organization, fn (): array => Customer::query()
                ->where('account_id', $account->getKey())
                ->orderBy('id')
                ->get()
                ->all());

            array_push($linked, ...$found);
        }

        return new self(null, $account, $linked);
    }

    public function isAccount(): bool
    {
        return $this->account !== null;
    }

    public function isScoped(): bool
    {
        return $this->account === null && $this->scopedCustomer !== null;
    }

    public function canUseFeed(): bool
    {
        return $this->isAccount();
    }

    /**
     * @return list<Customer>
     */
    public function customers(): array
    {
        if ($this->isAccount()) {
            return $this->linked;
        }

        return $this->scopedCustomer === null ? [] : [$this->scopedCustomer];
    }

    public function customerIn(int $organizationId): ?Customer
    {
        foreach ($this->customers() as $customer) {
            if ((int) $customer->organization_id === $organizationId) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * @throws ModelNotFoundException<Layaway>
     */
    public function findLayaway(string $code): Layaway
    {
        $customerIds = array_map(fn (Customer $customer): int => (int) $customer->getKey(), $this->customers());

        $layaway = $customerIds === []
            ? null
            : Layaway::query()->where('code', LayawayCode::normalize($code))->whereIn('customer_id', $customerIds)->first();

        if (! $layaway instanceof Layaway) {
            throw (new ModelNotFoundException)->setModel(Layaway::class, [$code]);
        }

        return $layaway;
    }
}
