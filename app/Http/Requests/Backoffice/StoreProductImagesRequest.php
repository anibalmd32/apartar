<?php

declare(strict_types=1);

namespace App\Http\Requests\Backoffice;

use App\Models\Product;
use App\Rules\RealMimeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Los archivos que llegan al subir fotos de un producto
 * (`REQ-BOF-022` RB-1, SP-1, SP-2, SP-3).
 *
 * Los tres límites de RB-1 viven aquí porque los tres se corrigen antes de
 * enviar —quitando un archivo, comprimiéndolo o eligiendo otro— y para eso el
 * error tiene que llegar junto a su archivo.
 *
 * ## El límite de 10 cuenta **las que ya hay**
 *
 * SP-1 es «la undécima imagen», no «la undécima de esta tanda»: quien tiene
 * ocho y sube cuatro se pasa. De ahí que la regla mire el producto de la ruta
 * y no solo el tamaño del array, y de ahí que el mensaje diga cuántas caben
 * todavía — «máximo 10» sobre un producto que ya tiene ocho deja a quien lo
 * lee haciendo la resta.
 *
 * ## Y el tipo se comprueba de verdad
 *
 * `RealMimeType` mira el contenido del archivo, no la extensión ni el
 * `Content-Type`, que son texto que escribe el cliente. El detalle está en la
 * regla.
 */
final class StoreProductImagesRequest extends FormRequest
{
    /** Imágenes por producto (RB-1). */
    public const MAX_IMAGES = 10;

    /** Tamaño máximo por archivo, en kilobytes: 8 MB (RB-1). */
    public const MAX_KILOBYTES = 8 * 1024;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.$this->remainingSlots()],

            'images.*' => [
                'required',
                'file',
                // El tope de tamaño va antes que el de tipo: leer el contenido
                // de un archivo de 40 MB para descubrir que además no es una
                // imagen es trabajo que no hacía falta.
                'max:'.self::MAX_KILOBYTES,
                new RealMimeType,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'images.max' => sprintf(
                'Un producto admite %d imágenes y a este le quedan %d.',
                self::MAX_IMAGES,
                $this->remainingSlots(),
            ),
            'images.*.max' => 'Cada imagen puede pesar hasta 8 MB.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['images' => 'imágenes'];
    }

    /**
     * Los archivos subidos, ya filtrados a los que de verdad lo son.
     *
     * @return list<UploadedFile>
     */
    public function images(): array
    {
        $files = $this->file('images', []);

        // Un solo archivo llega como `UploadedFile` y no como lista: el
        // `array` de la validación ya lo rechaza, pero esto evita que un
        // llamante distinto reciba un tipo que no espera.
        return is_array($files) ? array_values($files) : [];
    }

    /**
     * Cuántas imágenes más admite el producto (SP-1).
     *
     * Cero cuando está lleno, y entonces `max:0` rechaza cualquier tanda con
     * el mensaje que dice por qué.
     */
    private function remainingSlots(): int
    {
        $product = $this->route('product');

        $current = $product instanceof Product ? $product->getMedia(Product::IMAGES)->count() : 0;

        return max(0, self::MAX_IMAGES - $current);
    }
}
