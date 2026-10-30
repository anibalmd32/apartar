<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentAttachment;
use App\Domain\Payment\PaymentMethod;
use App\Domain\Payment\PaymentOrigin;
use Carbon\CarbonImmutable;

/**
 * @return array<string, null>
 */
function withoutEvidence(): array
{
    return ['bank' => null, 'reference' => null, 'file_path' => null, 'file_hash' => null, 'file_mime' => null, 'file_size' => null];
}

it('pasa a las columnas del pago con su método, su cuenta y su día', function (): void {
    $origin = new PaymentOrigin(PaymentMethod::Zelle, 7, CarbonImmutable::parse('2026-10-05'));

    expect($origin->toColumns())->toBe(['payment_method' => 'zelle', 'payment_account_id' => 7, 'paid_on' => '2026-10-05', ...withoutEvidence()]);
});

it('guarda el día tal cual, sin llevarlo a ninguna zona', function (): void {
    $origin = new PaymentOrigin(PaymentMethod::EfectivoUsd, null, CarbonImmutable::parse('2026-10-05 23:30:00', 'Asia/Tokyo'));

    expect($origin->toColumns())->toBe(['payment_method' => 'efectivo_usd', 'payment_account_id' => null, 'paid_on' => '2026-10-05', ...withoutEvidence()]);
});

it('lleva el banco, la referencia y el adjunto cuando los hay', function (): void {
    $hash = hash('sha256', 'captura');
    $origin = new PaymentOrigin(
        PaymentMethod::PagoMovil,
        null,
        CarbonImmutable::parse('2026-10-05'),
        'Banesco',
        '0123456789',
        new PaymentAttachment('7/captura.jpg', $hash, 'image/jpeg', 120_000),
    );

    expect($origin->toColumns())->toBe([
        'payment_method' => 'pago_movil',
        'payment_account_id' => null,
        'paid_on' => '2026-10-05',
        'bank' => 'Banesco',
        'reference' => '0123456789',
        'file_path' => '7/captura.jpg',
        'file_hash' => $hash,
        'file_mime' => 'image/jpeg',
        'file_size' => 120_000,
    ]);
});
