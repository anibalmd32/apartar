<?php

declare(strict_types=1);

use App\Actions\Customer\CustomerContactData;
use App\Actions\Layaway\BackofficeLayawayData;
use App\Actions\Payment\ManualPaymentData;
use App\Domain\Customer\TermsConsent;
use App\Domain\Layaway\DownPaymentWindow;
use App\Domain\Money\Currency;
use App\Domain\Payment\PaymentMethod;
use App\Models\Customer;
use App\Models\Product;
use Carbon\CarbonImmutable;

function backofficeLayawayData(
    int $quantity = 1,
    ?Customer $customer = null,
    bool $withContact = true,
    bool $withConsent = true,
    string $key = '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f',
    ?string $paymentKey = null,
): BackofficeLayawayData {
    return new BackofficeLayawayData(
        product: new Product,
        quantity: $quantity,
        customer: $customer,
        contact: $withContact ? CustomerContactData::fromInput('V-12345678', 'María González', '0414-1234567', null) : null,
        consent: $withConsent ? TermsConsent::acceptedNowFrom('190.202.10.5') : null,
        window: DownPaymentWindow::organizationDefault(),
        idempotencyKey: $key,
        payment: $paymentKey === null ? null : new ManualPaymentData(
            PaymentMethod::EfectivoUsd,
            null,
            '50.00',
            Currency::USD,
            null,
            CarbonImmutable::parse('2026-09-27'),
            'Efectivo recibido en tienda por Ana al apartar.',
            $paymentKey,
        ),
    );
}

it('acepta un cliente nuevo con su consentimiento declarado', function (): void {
    $data = backofficeLayawayData(quantity: 2);

    expect($data->quantity)->toBe(2)
        ->and($data->customer)->toBeNull()
        ->and($data->contact?->fullName)->toBe('María González')
        ->and($data->payment)->toBeNull();
});

it('acepta un pago al crear que comparte la clave del apartado', function (): void {
    $data = backofficeLayawayData(paymentKey: '6f1c2d3e-4a5b-4c6d-8e7f-9a0b1c2d3e4f');

    expect($data->payment?->idempotencyKey)->toBe($data->idempotencyKey);
});

it('acepta una ficha existente sin contacto ni consentimiento', function (): void {
    $data = backofficeLayawayData(customer: new Customer, withContact: false, withConsent: false);

    expect($data->customer)->toBeInstanceOf(Customer::class)->and($data->contact)->toBeNull();
});

it('rechaza datos incoherentes', function (Closure $build): void {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'cantidad cero' => [fn () => backofficeLayawayData(quantity: 0)],
    'cantidad de más' => [fn () => backofficeLayawayData(quantity: 11)],
    'ficha y contacto' => [fn () => backofficeLayawayData(customer: new Customer)],
    'ni ficha ni contacto' => [fn () => backofficeLayawayData(withContact: false)],
    'contacto sin consentimiento' => [fn () => backofficeLayawayData(withConsent: false)],
    'clave que no es UUID' => [fn () => backofficeLayawayData(key: 'abc')],
    'pago con otra clave' => [fn () => backofficeLayawayData(paymentKey: '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d')],
]);
