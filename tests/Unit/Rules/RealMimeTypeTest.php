<?php

declare(strict_types=1);

use App\Rules\RealMimeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Translation\Translator;

/**
 * El tipo real de un archivo subido (`REQ-BOF-022` RB-1, SP-3).
 *
 * **Lo que se prueba es que la extensión y la cabecera no deciden nada.** Las
 * dos las elige quien sube; lo único que vale es el contenido. El daño de no
 * comprobarlo no es guardar basura: es que un archivo ejecutable con nombre
 * `.jpg` acabe servido desde el disco público del negocio.
 *
 * ## Por qué los archivos son reales y no `UploadedFile::fake()`
 *
 * Porque el doble de Laravel **deduce su tipo del nombre**
 * (`Testing\File::getMimeType()` devuelve `MimeType::from($this->name)`), que
 * es justamente lo que esta regla existe para no hacer. Un test escrito con
 * `fake()` pasaría con la regla borrada. Así que cada caso escribe un fichero
 * temporal con contenido de verdad y construye el `UploadedFile` sobre él.
 *
 * La regla se invoca directamente y no a través del validador, como en
 * `ValidPaymentIdentifierTest`: son tests de unidad y no arrancan la
 * aplicación, así que no hay fachadas que resolver.
 */
function failsRealMime(mixed $file): bool
{
    $failed = false;
    $translator = new Translator(new ArrayLoader, 'es');

    (new RealMimeType)->validate(
        'image',
        $file,
        function (string $message) use (&$failed, $translator): PotentiallyTranslatedString {
            $failed = true;

            return new PotentiallyTranslatedString($message, $translator);
        },
    );

    return $failed;
}

/**
 * Un archivo subido de verdad: contenido en disco y el `Content-Type` que
 * quiera declarar el cliente.
 */
function uploaded(string $name, string $contents, string $declaredMime = 'application/octet-stream'): UploadedFile
{
    $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $declaredMime, null, true);
}

/**
 * Una imagen de verdad de cada formato.
 *
 * JPEG, PNG y GIF los genera GD —el mismo `image_driver` que usa el
 * proyecto—; el WebP se compone a mano porque la extensión GD del contenedor
 * no trae `imagewebp()`, y una cabecera RIFF/WEBP completa es suficiente para
 * que la adivinación por contenido lo reconozca. Lo importante es que ninguno
 * de los cuatro dependa del nombre del archivo.
 */
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

/**
 * **SP-3, el caso que da nombre a la regla.** Un archivo que no es una imagen
 * con la extensión cambiada se rechaza: lo que se mira es el contenido.
 */
it('rechaza lo que no es una imagen aunque se llame como una', function (): void {
    expect(failsRealMime(uploaded('malicioso.jpg', '<?php echo "no soy una foto";')))->toBeTrue();
});

/**
 * Y tampoco se fía de la cabecera que manda el navegador, que es texto que
 * escribe el cliente: el archivo **dice** ser un PNG y su contenido no lo es.
 */
it('ignora el Content-Type que declara el cliente', function (): void {
    expect(failsRealMime(uploaded('no-es-una-imagen.png', 'esto es texto plano', 'image/png')))->toBeTrue();
});

/**
 * Un formato de imagen legítimo que el catálogo no admite tampoco pasa: la
 * lista de RB-1 es cerrada, y aceptar un GIF o un SVG «porque también es una
 * imagen» metería en el disco público un formato que ni se convierte ni se
 * sirve igual —el SVG, además, ejecuta scripts—.
 */
it('rechaza los formatos de imagen que el catálogo no admite', function (): void {
    expect(failsRealMime(uploaded('animacion.gif', imageContent('image/gif'))))->toBeTrue();
});

/**
 * **La otra mitad, y la que se olvida:** un PNG de verdad guardado con una
 * extensión que el servidor web serviría de otra forma. El tipo real decide si
 * se acepta; la extensión decide cómo se sirve una vez en disco.
 */
it('exige que la extensión corresponda al tipo real', function (): void {
    expect(failsRealMime(uploaded('foto.php', imageContent('image/png'))))->toBeTrue();
});

/**
 * Lo que no es un archivo lo dice la regla `file` con su mensaje: dos mensajes
 * para el mismo fallo se leen como dos problemas distintos.
 */
it('no opina sobre lo que ni siquiera es un archivo', function (): void {
    expect(failsRealMime('una cadena'))->toBeFalse();
});
