<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Jobs\TranscodeProductImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Una imagen de producto en sus tres tamaños (`REQ-CAT-005`).
 *
 * Es el contrato `ImageSet` del módulo [11] —`thumb`, `card`, `zoom`, `alt`—
 * y llega **antes que las pantallas que lo montan**: la galería pública es de
 * `T-033` y `T-035`. Es el mismo reparto que `T-028` hizo con la agrupación de
 * la ficha técnica: la pieza y su prueba ahora, la página en su tarea.
 *
 * ## Mientras una conversión no exista, se sirve el original
 *
 * RB-3, y es la regla que sostiene todo lo demás. Las conversiones se generan
 * en cola (RB-1), así que entre subir una foto y tenerla en WebP pasan
 * segundos —o minutos, si la cola va cargada, o nunca, si el job falló
 * (SP-1)—. En esa ventana la ficha **funciona**: cada tamaño cae al archivo
 * original en lugar de a un hueco roto. Un producto publicable cuyas fotos
 * dependan de que la cola vaya bien es un catálogo que se apaga cuando cae un
 * *worker*.
 *
 * El `loading="lazy"` que RB-3 nombra es de quien pinta la etiqueta, no de
 * aquí: esto compone URLs.
 *
 * ## El `alt` nunca viaja vacío
 *
 * RB-4 fija el defecto —«{nombre} — imagen {n}»— y RB-6 lo hace editable. Un
 * `alt` vacío o repetido en las diez imágenes es una ficha que no se puede
 * leer con lector de pantalla, y el defecto existe para que eso no dependa de
 * que alguien se acuerde de escribirlo.
 */
final readonly class ImageSet
{
    /**
     * Propiedad del `media` donde vive el texto alternativo (RB-6).
     */
    public const ALT_PROPERTY = 'alt';

    /**
     * Las tres conversiones de `REQ-CAT-005`, con su anchura en píxeles.
     *
     * `thumb` para las cards del backoffice, `card` para la parrilla del
     * catálogo y `zoom` para la ficha ampliada. Declaradas aquí y consumidas
     * por `Product::registerMediaConversions()`: el tamaño de `card` se lee en
     * los dos sitios —quien las genera y quien las sirve— y dos listas de las
     * mismas tres acaban discrepando.
     *
     * @var array<string, int>
     */
    public const CONVERSIONS = [
        'thumb' => 400,
        'card' => 800,
        'zoom' => 1600,
    ];

    /**
     * Calidad del WebP, fijada por `REQ-CAT-005`.
     *
     * WebP y 82 porque el canal real es un móvil con datos contados: el peso
     * de la imagen es lo que decide si la ficha se ve o se abandona, y por
     * encima de ahí la diferencia deja de notarse en pantalla y sigue
     * notándose en la factura de quien mira.
     */
    public const QUALITY = 82;

    public function __construct(
        public string $thumb,
        public string $card,
        public string $zoom,
        public string $alt,
    ) {}

    /**
     * El juego de URLs de ese medio.
     */
    public static function of(Media $media): self
    {
        return new self(
            thumb: self::urlFor($media, 'thumb'),
            card: self::urlFor($media, 'card'),
            zoom: self::urlFor($media, 'zoom'),
            alt: self::altOf($media),
        );
    }

    /**
     * El texto alternativo por defecto de una imagen (RB-4).
     *
     * `$number` es la posición que ocupaba **al subirse**, y el texto no se
     * renumera después: es un valor por defecto que el negocio puede editar, y
     * reescribirlo al reordenar borraría lo que hubiera escrito.
     */
    public static function defaultAlt(string $productName, int $number): string
    {
        return sprintf('%s — imagen %d', $productName, $number);
    }

    /**
     * Si la conversión de este medio quedó pendiente de revisión (SP-1, SP-6).
     *
     * No es parte del contrato `ImageSet` —el cliente final no tiene por qué
     * enterarse de la cola— pero sí de lo que ve el negocio en su galería,
     * así que vive aquí, junto a la razón por la que la imagen sigue
     * sirviéndose igual.
     */
    public static function needsReview(Media $media): bool
    {
        return (bool) $media->getCustomProperty(TranscodeProductImage::REVIEW_PROPERTY, false);
    }

    /**
     * @return array{thumb: string, card: string, zoom: string, alt: string}
     */
    public function toArray(): array
    {
        return [
            'thumb' => $this->thumb,
            'card' => $this->card,
            'zoom' => $this->zoom,
            'alt' => $this->alt,
        ];
    }

    /**
     * La URL de un tamaño, o la del original si esa conversión todavía no
     * existe (RB-3).
     */
    private static function urlFor(Media $media, string $conversion): string
    {
        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }

    /**
     * El `alt` guardado, o el defecto si nadie lo escribió.
     *
     * El defecto se compone aquí y no se busca en el producto —eso sería una
     * consulta por imagen—: el nombre viaja en `custom_properties` desde la
     * subida, porque es lo que el medio necesita para describirse solo.
     */
    private static function altOf(Media $media): string
    {
        $alt = $media->getCustomProperty(self::ALT_PROPERTY);

        return is_string($alt) && trim($alt) !== '' ? trim($alt) : $media->name;
    }
}
