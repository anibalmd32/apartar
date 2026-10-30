<?php

declare(strict_types=1);

use App\Domain\Payment\PaymentAttachment;

it('pasa a las columnas del archivo del pago', function (): void {
    $hash = hash('sha256', 'captura');

    expect((new PaymentAttachment('7/captura.jpg', $hash, 'image/jpeg', 120_000))->toColumns())->toBe([
        'file_path' => '7/captura.jpg',
        'file_hash' => $hash,
        'file_mime' => 'image/jpeg',
        'file_size' => 120_000,
    ]);
});

it('no admite un adjunto sin ruta, sin huella, sin tipo o vacío', function (string $path, string $hash, string $mime, int $size): void {
    new PaymentAttachment($path, $hash, $mime, $size);
})->throws(InvalidArgumentException::class)->with([
    'sin ruta' => ['', str_repeat('a', 64), 'image/jpeg', 1],
    'sin huella' => ['7/captura.jpg', '', 'image/jpeg', 1],
    'sin tipo' => ['7/captura.jpg', str_repeat('a', 64), '', 1],
    'vacío' => ['7/captura.jpg', str_repeat('a', 64), 'image/jpeg', 0],
]);
