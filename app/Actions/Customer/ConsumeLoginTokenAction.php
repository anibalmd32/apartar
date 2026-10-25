<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Auth\PortalLogin;
use App\Domain\Customer\LoginLinkToken;
use App\Domain\Customer\UnusableLoginLinkException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\CustomerLoginToken;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ConsumeLoginTokenAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly PortalLogin $login,
    ) {}

    /**
     * @throws UnusableLoginLinkException
     */
    /**
     * @return array{businessName: string, document: string|null}|null
     */
    public function preview(string $plainToken): ?array
    {
        $organizationId = LoginLinkToken::organizationIdIn($plainToken);

        if ($organizationId === null) {
            return null;
        }

        try {
            $organization = $this->resolver->forOrganizationId($organizationId);
        } catch (ModelNotFoundException) {
            return null;
        }

        return $this->context->runFor($organization, function () use ($plainToken, $organization): ?array {
            $token = CustomerLoginToken::query()->where('token_hash', LoginLinkToken::hash($plainToken))->first();
            $customer = $token instanceof CustomerLoginToken && $token->isUsable() ? Customer::query()->find($token->customer_id) : null;

            if (! $customer instanceof Customer) {
                return null;
            }

            return [
                'businessName' => $organization->name,
                'document' => DocumentNumber::tryFromInput($customer->document_number)?->masked(),
            ];
        });
    }

    public function handle(string $plainToken, bool $remember = true): Customer
    {
        $organizationId = LoginLinkToken::organizationIdIn($plainToken)
            ?? throw new UnusableLoginLinkException('token sin prefijo de organización');

        try {
            $organization = $this->resolver->forOrganizationId($organizationId);
        } catch (ModelNotFoundException) {
            throw new UnusableLoginLinkException('prefijo de una organización que no existe');
        }

        $outcome = $this->context->runFor($organization, fn (): Customer|string => DB::transaction(function () use ($plainToken): Customer|string {
            $token = CustomerLoginToken::query()
                ->where('token_hash', LoginLinkToken::hash($plainToken))
                ->lockForUpdate()
                ->first();

            if (! $token instanceof CustomerLoginToken) {
                return 'token inexistente';
            }

            $now = CarbonImmutable::now();

            if (! $token->isUsable()) {
                if ($token->consumed_at === null) {
                    $token->forceFill(['consumed_at' => $now])->save();
                }

                return 'token caducado o ya usado';
            }

            $token->forceFill(['consumed_at' => $now])->save();

            $customer = Customer::query()->findOrFail($token->customer_id);
            $customer->forceFill(['last_login_at' => $now])->save();

            return $customer;
        }));

        if (is_string($outcome)) {
            throw new UnusableLoginLinkException($outcome);
        }

        $this->login->open($outcome, $remember);

        return $outcome;
    }
}
