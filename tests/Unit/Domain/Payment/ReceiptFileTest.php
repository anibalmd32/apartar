<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptFile;
use App\Rules\RealMimeType;
use Illuminate\Http\UploadedFile;

it('compone la ruta con la organización y un UUID', function (): void {
    expect(ReceiptFile::pathFor(42, 'JPG'))
        ->toMatch('#^42/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg$#');
});

it('da una ruta distinta cada vez', function (): void {
    expect(ReceiptFile::pathFor(7, 'pdf'))->not->toBe(ReceiptFile::pathFor(7, 'pdf'));
});

it('no compone una ruta sin organización', function (int $organizationId): void {
    ReceiptFile::pathFor($organizationId, 'jpg');
})->with([0, -3])->throws(InvalidArgumentException::class);

it('no acepta como extensión lo que no lo es', function (string $extension): void {
    ReceiptFile::pathFor(1, $extension);
})->with(['', 'jpg/../../x', 'p.hp', 'demasiadolarga'])->throws(InvalidArgumentException::class);

it('calcula el sha256 del archivo subido', function (): void {
    $path = sys_get_temp_dir().'/'.uniqid('apartar-receipt-', true);
    file_put_contents($path, 'la captura del pago móvil');

    expect(ReceiptFile::fingerprint(new UploadedFile($path, 'captura.jpg', 'image/jpeg', null, true)))
        ->toBe(hash('sha256', 'la captura del pago móvil'));
});

it('declara los límites de tamaño y tipo', function (): void {
    expect(ReceiptFile::MAX_SIZE_MB)->toBe(5)
        ->and(ReceiptFile::MAX_PIXELS)->toBe(40_000_000)
        ->and(ReceiptFile::ACCEPT)->toBe('.jpg,.jpeg,.png,.webp,.pdf');
});

it('ofrece en el accept exactamente las extensiones que se admiten', function (): void {
    $admitted = array_merge(...array_values(RealMimeType::RECEIPT_TYPES));

    expect(explode(',', ReceiptFile::ACCEPT))->toEqualCanonicalizing(array_map(fn (string $extension): string => '.'.$extension, $admitted));
});

it('sin aplicación, el tope es su default', function (): void {
    expect(ReceiptFile::maxSizeMb())->toBe(ReceiptFile::MAX_SIZE_MB);
});

it('da como identificador público el UUID del archivo, sin la organización ni la extensión', function (): void {
    $path = ReceiptFile::pathFor(42, 'jpg');

    expect(ReceiptFile::publicIdOf($path))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($path)->toBe('42/'.ReceiptFile::publicIdOf($path).'.jpg');
});
