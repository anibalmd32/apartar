<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Domain\Account\ClaimVerificationFailedException;
use App\Domain\Account\CustomerAlreadyLinkedException;
use App\Domain\Account\LinkOrigin;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Layaway;
use App\Support\DocumentNumber;
use App\Support\LayawayCode;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ClaimCustomerAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly LinkAccountAction $link,
    ) {}

    /**
     * @throws ClaimVerificationFailedException
     * @throws CustomerAlreadyLinkedException
     */
    public function handle(Account $account, DocumentNumber $document, string $layawayCode): Customer
    {
        $code = LayawayCode::normalize($layawayCode);

        if (! LayawayCode::isWellFormed($code)) {
            throw new ClaimVerificationFailedException('código mal formado');
        }

        try {
            $organization = $this->resolver->forLayawayCode($code);
        } catch (ModelNotFoundException) {
            throw new ClaimVerificationFailedException('código inexistente');
        }

        return $this->context->runFor($organization, function () use ($account, $document, $code): Customer {
            $layaway = Layaway::query()->where('code', $code)->first();
            $customer = $layaway instanceof Layaway
                ? Customer::query()->whereKey($layaway->customer_id)->where('document_number', $document->value)->first()
                : null;

            if (! $customer instanceof Customer) {
                throw new ClaimVerificationFailedException('el documento no es el de la ficha del apartado');
            }

            $this->link->handle($customer, $account, LinkOrigin::VerifiedClaim);

            return $customer;
        });
    }
}
