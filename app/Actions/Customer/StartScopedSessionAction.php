<?php

declare(strict_types=1);

namespace App\Actions\Customer;

use App\Auth\PortalLogin;
use App\Domain\Customer\AccessVerificationFailedException;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Models\Customer;
use App\Models\CustomerMerge;
use App\Models\Layaway;
use App\Support\DocumentNumber;
use App\Support\LayawayCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class StartScopedSessionAction
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantResolver $resolver,
        private readonly PortalLogin $login,
    ) {}

    /**
     * @throws AccessVerificationFailedException
     */
    public function handle(DocumentNumber $document, string $layawayCode): Customer
    {
        $code = LayawayCode::normalize($layawayCode);

        if (! LayawayCode::isWellFormed($code)) {
            throw new AccessVerificationFailedException('código mal formado');
        }

        try {
            $organization = $this->resolver->forLayawayCode($code);
        } catch (ModelNotFoundException) {
            throw new AccessVerificationFailedException('código inexistente');
        }

        $customer = $this->context->runFor($organization, function () use ($code, $document): Customer {
            $layaway = Layaway::query()->where('code', $code)->first();

            $customer = $layaway instanceof Layaway
                ? Customer::query()->whereKey($layaway->customer_id)->where('document_number', $document->value)->first()
                : null;

            if (! $customer instanceof Customer && $layaway instanceof Layaway && CustomerMerge::query()
                ->where('survivor_customer_id', $layaway->customer_id)
                ->where('absorbed_document_number', $document->value)
                ->exists()) {
                $customer = Customer::query()->find($layaway->customer_id);
            }

            if (! $customer instanceof Customer) {
                throw new AccessVerificationFailedException('el documento no es el de la ficha del apartado');
            }

            $customer->forceFill(['last_login_at' => CarbonImmutable::now()])->save();

            return $customer;
        });

        $this->login->open($customer);

        return $customer;
    }
}
