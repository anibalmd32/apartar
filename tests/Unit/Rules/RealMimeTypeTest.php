<?php

declare(strict_types=1);

use App\Rules\RealMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

function failsRealMime(mixed $file, ?RealMimeType $rule = null): bool
{
    return realMimeMessage($file, $rule) !== null;
}

function realMimeMessage(mixed $file, ?RealMimeType $rule = null): ?string
{
    $failure = null;
    $translator = new Translator(new ArrayLoader, 'es');

    ($rule ?? new RealMimeType)->validate(
        'image',
        $file,
        function (string $message) use (&$failure, $translator): PotentiallyTranslatedString {
            $failure = $message;

            return new PotentiallyTranslatedString($message, $translator);
        },
    );

    return $failure;
}

function pdfContent(): string
{
    return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n"
        ."2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n"
        ."trailer\n<< /Root 1 0 R >>\n%%EOF\n";
}

function executableContent(): string
{
    return "\x7FELF\x02\x01\x01\x00".str_repeat("\x00", 8)."\x02\x00\x3E\x00\x01\x00\x00\x00".str_repeat("\x00", 40);
}

function uploaded(string $name, string $contents, string $declaredMime = 'application/octet-stream'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $declaredMime, null, true);
}

function imageContent(string $mime): string
{
    if ($mime === 'image/webp') {
        $data = 'WEBPVP8L'.pack('V', 10).str_repeat("\x2f", 10);

        return 'RIFF'.pack('V', strlen($data)).$data;
    }

    $image = imagecreatetruecolor(4, 4);
    $path = sys_get_temp_dir().'/'.uniqid('apartar-source-', true);

    match ($mime) {
        'image/jpeg' => imagejpeg($image, $path),
        'image/png' => imagepng($image, $path),
        'image/gif' => imagegif($image, $path),
        default => imagepng($image, $path),
    };

    return (string) file_get_contents($path);
}

it('acepta los tres formatos del catálogo', function (string $name, string $mime): void {
    expect(failsRealMime(uploaded($name, imageContent($mime))))->toBeFalse();
})->with([
    'jpg' => ['foto.jpg', 'image/jpeg'],
    'jpeg' => ['foto.jpeg', 'image/jpeg'],
    'png' => ['foto.png', 'image/png'],
    'webp' => ['foto.webp', 'image/webp'],
]);

it('rechaza lo que no es una imagen aunque se llame como una', function (): void {
    expect(failsRealMime(uploaded('malicioso.jpg', '<?php echo "no soy una foto";')))->toBeTrue();
});

it('ignora el Content-Type que declara el cliente', function (): void {
    expect(failsRealMime(uploaded('no-es-una-imagen.png', 'esto es texto plano', 'image/png')))->toBeTrue();
});

it('rechaza los formatos de imagen que el catálogo no admite', function (): void {
    expect(failsRealMime(uploaded('animacion.gif', imageContent('image/gif'))))->toBeTrue();
});

it('exige que la extensión corresponda al tipo real', function (): void {
    expect(failsRealMime(uploaded('foto.php', imageContent('image/png'))))->toBeTrue();
});

it('no opina sobre lo que ni siquiera es un archivo', function (): void {
    expect(failsRealMime('una cadena'))->toBeFalse();
});

it('dice qué imágenes admite el catálogo', function (): void {
    expect(realMimeMessage(uploaded('malicioso.jpg', '<?php echo 1;')))
        ->toStartWith('El archivo no es una imagen JPG, PNG ni WebP.');
});

it('no admite PDF en el catálogo', function (): void {
    expect(failsRealMime(uploaded('ficha.pdf', pdfContent())))->toBeTrue()
        ->and(RealMimeType::ALLOWED)->not->toHaveKey('application/pdf');
});

it('acepta las imágenes y el PDF en un comprobante', function (string $name, string $mime): void {
    $contents = $mime === 'application/pdf' ? pdfContent() : imageContent($mime);

    expect(failsRealMime(uploaded($name, $contents), RealMimeType::receipts()))->toBeFalse();
})->with([
    'jpg' => ['captura.jpg', 'image/jpeg'],
    'jpeg' => ['captura.jpeg', 'image/jpeg'],
    'png' => ['captura.png', 'image/png'],
    'webp' => ['captura.webp', 'image/webp'],
    'pdf' => ['justificante.pdf', 'application/pdf'],
]);

it('rechaza un ejecutable con nombre de comprobante', function (string $name, string $declared): void {
    expect(failsRealMime(uploaded($name, executableContent(), $declared), RealMimeType::receipts()))->toBeTrue();
})->with([
    'pdf' => ['justificante.pdf', 'application/pdf'],
    'jpg' => ['captura.jpg', 'image/jpeg'],
]);

it('exige que la extensión de un comprobante corresponda a su tipo real', function (): void {
    expect(failsRealMime(uploaded('captura.jpg', pdfContent()), RealMimeType::receipts()))->toBeTrue();
});

it('nombra el PDF en el mensaje del comprobante', function (): void {
    expect(realMimeMessage(uploaded('captura.gif', imageContent('image/gif')), RealMimeType::receipts()))
        ->toStartWith('El archivo no es una imagen JPG, PNG o WebP ni un PDF.');
});
