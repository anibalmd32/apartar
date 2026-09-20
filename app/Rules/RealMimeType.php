<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Comprueba que un archivo subido **es** lo que dice ser
 * (`REQ-BOF-022` RB-1, SP-3).
 *
 * ## Lo que se mira es el contenido, no lo que manda el navegador
 *
 * Un `Content-Type` es texto que escribe el cliente, y una extensión es parte
 * del nombre del archivo: las dos las elige quien sube y las dos se pueden
 * poner a mano. La regla `mimes:jpg,png,webp` de Laravel ya mira el contenido
 * —adivina el tipo con `finfo` y lo compara con la extensión— pero acepta
 * cualquier extensión que corresponda al tipo adivinado, y lo que hace falta
 * decir aquí es más estrecho y más explícito: **estos tres tipos reales y
 * ningún otro**.
 *
 * El daño de no hacerlo no es que se guarde basura: es que un archivo
 * ejecutable con nombre `.jpg` acabe servido desde el disco público del
 * negocio. Por eso la comprobación es del tipo real y por eso la lista es
 * cerrada.
 *
 * ## Y también mira la extensión, por una razón distinta
 *
 * El tipo real decide **si se acepta**; la extensión decide **cómo lo va a
 * servir el servidor web** cuando el archivo esté en disco. Un PNG legítimo
 * guardado como `foto.php` es un archivo válido para esta regla y una bomba
 * para nginx, así que las dos tienen que coincidir. Es la misma precaución que
 * `media-library.php` toma con su lista de extensiones bloqueadas, escrita
 * donde se decide qué entra.
 */
final class RealMimeType implements ValidationRule
{
    /**
     * Los tres tipos que admite el catálogo (RB-1).
     *
     * `image/jpeg` cubre `.jpg` y `.jpeg`, que es la misma cosa con dos
     * nombres desde hace treinta años.
     *
     * @var array<string, list<string>> tipo real => extensiones que le corresponden
     */
    public const ALLOWED = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            // Que sea un archivo lo dice la regla `file` con su mensaje. Aquí
            // solo se comprueba qué archivo es, y dos mensajes para el mismo
            // fallo se leen como dos problemas distintos.
            return;
        }

        // `getMimeType()` y **no** `getClientMimeType()`: el primero abre el
        // archivo y lo adivina por su contenido, el segundo devuelve la
        // cabecera que mandó el navegador.
        $mime = (string) $value->getMimeType();

        if (! array_key_exists($mime, self::ALLOWED)) {
            $fail('El archivo no es una imagen JPG, PNG ni WebP. Comprueba que no sea otra cosa con el nombre cambiado.');

            return;
        }

        $extension = mb_strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED[$mime], true)) {
            $fail(sprintf(
                'El archivo es %s pero su nombre termina en «.%s». Renómbralo para que coincidan.',
                $mime,
                $extension,
            ));
        }
    }
}
