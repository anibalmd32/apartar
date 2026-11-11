<?php

declare(strict_types=1);

use App\Domain\Payment\ReceiptFile;
use App\Rules\WithinPixelLimit;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;
use Tests\Support\ImageUploads;

function pixelLimitMessage(WithinPixelLimit $rule, mixed $value): ?string
{
    $failure = null;
    $translator = new Translator(new ArrayLoader, 'es');

    $rule->validate('foto', $value, function (string $message) use (&$failure, $translator): PotentiallyTranslatedString {
        $failure = $message;

        return new PotentiallyTranslatedString($message, $translator);
    });

    return $failure;
}

test('las fotos de celular de hasta 50 MP pasan y las de más de 60 MP no', function (): void {
    $rule = WithinPixelLimit::photos();

    expect(pixelLimitMessage($rule, ImageUploads::real('a.png', ImageUploads::pngHeader(4000, 3000))))->toBeNull()
        ->and(pixelLimitMessage($rule, ImageUploads::real('b.png', ImageUploads::pngHeader(8160, 6144))))->toBeNull()
        ->and(pixelLimitMessage($rule, ImageUploads::real('c.png', ImageUploads::pngHeader(10000, 6001))))
        ->toBe('La imagen pasa de 60 megapíxeles. Tómala con la resolución normal de la cámara o redúcela antes de subirla.');
});

test('los comprobantes conservan su límite de 40 MP y su mensaje', function (): void {
    $rule = WithinPixelLimit::receipts();

    expect($rule->maxPixels)->toBe(ReceiptFile::MAX_PIXELS)
        ->and(pixelLimitMessage($rule, ImageUploads::real('a.png', ImageUploads::pngHeader(8000, 5001))))
        ->toBe('La imagen es demasiado grande en píxeles. Sube una captura de pantalla normal.');
});

test('lo que no es una imagen lo deja pasar para que lo juzguen otras reglas', function (): void {
    expect(pixelLimitMessage(WithinPixelLimit::photos(), 'texto'))->toBeNull()
        ->and(pixelLimitMessage(WithinPixelLimit::photos(), ImageUploads::real('a.pdf', str_pad("%PDF-1.4\n", 512, '%'))))->toBeNull();
});
