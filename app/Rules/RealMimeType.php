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
 *
 * ## Dos listas cerradas, no una abierta
 *
 * `new RealMimeType` es la del catálogo; `RealMimeType::receipts()`, la del
 * comprobante de pago, que admite además PDF (`REQ-PAY-002` RB-5, SP-1). Cada
 * sitio que sube archivos elige la suya y ninguno hereda lo que otro necesita.
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
     * Los tipos de un comprobante de pago (`REQ-PAY-002` RB-5): los del
     * catálogo y el PDF, que es como muchos bancos entregan el justificante.
     *
     * Es una lista aparte y no una ampliación de `ALLOWED` porque esa la lee
     * también `Product::acceptsMimeTypes()`: un PDF en la galería de un
     * producto, servido desde el disco público, es justo lo que la lista
     * cerrada existe para impedir.
     *
     * @var array<string, list<string>>
     */
    public const RECEIPT_TYPES = self::ALLOWED + ['application/pdf' => ['pdf']];

    /**
     * Cómo se nombra cada tipo en el mensaje de error.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'image/jpeg' => 'JPG',
        'image/png' => 'PNG',
        'image/webp' => 'WebP',
        'application/pdf' => 'PDF',
    ];

    /**
     * @param  array<string, list<string>>  $allowed  tipo real => extensiones que le corresponden
     */
    public function __construct(private readonly array $allowed = self::ALLOWED) {}

    /**
     * La variante del comprobante de pago: imágenes y PDF.
     */
    public static function receipts(): self
    {
        return new self(self::RECEIPT_TYPES);
    }

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

        if (! array_key_exists($mime, $this->allowed)) {
            $fail(sprintf('El archivo no es %s. Comprueba que no sea otra cosa con el nombre cambiado.', $this->describeAllowed()));

            return;
        }

        $extension = mb_strtolower($value->getClientOriginalExtension());

        if (! in_array($extension, $this->allowed[$mime], true)) {
            $fail(sprintf(
                'El archivo es %s pero su nombre termina en «.%s». Renómbralo para que coincidan.',
                $mime,
                $extension,
            ));
        }
    }

    /**
     * Lo que se admite, dicho como lo leería quien sube: «una imagen JPG, PNG
     * ni WebP» para el catálogo, «una imagen JPG, PNG o WebP ni un PDF» para
     * el comprobante. Un mensaje que dijera solo «imagen» a quien acaba de
     * subir un PDF válido de su banco le haría creer que el PDF no vale.
     */
    private function describeAllowed(): string
    {
        $images = [];
        $documents = [];

        foreach (array_keys($this->allowed) as $mime) {
            $label = self::LABELS[$mime] ?? $mime;

            if (str_starts_with($mime, 'image/')) {
                $images[] = $label;
            } else {
                $documents[] = 'un '.$label;
            }
        }

        $parts = [];

        if ($images !== []) {
            $last = array_pop($images);
            $parts[] = 'una imagen '.($images === [] ? $last : implode(', ', $images).($documents === [] ? ' ni ' : ' o ').$last);
        }

        return implode(' ni ', [...$parts, ...$documents]);
    }
}
