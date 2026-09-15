<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * La identidad que se guarda de un negocio (`REQ-ORG-006`).
 *
 * Existe por lo mismo que `RegisterOrganizationData`: para que la acción
 * reciba datos ya normalizados y no un array suelto. Quien la construye —hoy
 * `UpdateOrganizationRequest`, mañana un comando— decide de dónde salen los
 * valores, y la acción no tiene que volver a preguntárselo.
 *
 * **`phone` sí tiene columna aquí**, a diferencia de lo que pasaba en el
 * registro: `T-014` añade `organizations.phone` y es esta pantalla la que lo
 * captura. En el alta no se pide —`REQ-ORG-001` RB-8 quiere un registro sin
 * fricción— y por eso `RegisterOrganizationData` deja de declararlo.
 */
final readonly class UpdateOrganizationData
{
    /**
     * @param  string  $name  nombre del negocio
     * @param  string  $slug  enlace de la vitrina; el mismo si no se cambia
     * @param  string|null  $document  RIF, obligatorio solo para facturar (`REQ-ORG-001` RB-7)
     * @param  string|null  $phone  teléfono de contacto del negocio
     * @param  string  $timezone  zona con la que se presentan sus fechas (RB-7)
     * @param  string|null  $brandColor  color de marca en hexadecimal, o nulo
     * @param  bool  $storefrontEnabled  vitrina `/{org-slug}` visible o no (RB-6)
     * @param  UploadedFile|null  $logo  logo nuevo; nulo deja el que hubiera
     */
    public function __construct(
        public string $name,
        public string $slug,
        public ?string $document,
        public ?string $phone,
        public string $timezone,
        public ?string $brandColor,
        public bool $storefrontEnabled,
        public ?UploadedFile $logo = null,
    ) {}

    /**
     * Color normalizado a `#rrggbb` en minúsculas, o nulo.
     *
     * Se normaliza porque el mismo color escrito `#FFF` y `#ffffff` es el
     * mismo color, y guardarlo de dos formas convertiría cualquier comparación
     * futura —«¿cambió el branding?»— en una respuesta falsa.
     */
    public function normalisedBrandColor(): ?string
    {
        $color = $this->brandColor;

        if ($color === null || trim($color) === '') {
            return null;
        }

        $color = Str::lower(trim($color));

        // Forma corta de CSS: `#abc` es `#aabbcc`.
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $matches) === 1) {
            return '#'.$matches[1].$matches[1].$matches[2].$matches[2].$matches[3].$matches[3];
        }

        return $color;
    }
}
