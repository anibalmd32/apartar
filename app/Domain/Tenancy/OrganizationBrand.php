<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * La identidad con la que un negocio se presenta (`REQ-ORG-006`).
 *
 * **`brandingEnabled` es lo único que no sale de la fila**, y en esa
 * separación está toda la regla de RB-5b: el logo y el color **se guardan
 * siempre**; lo que el plan gobierna es **aplicarlos**. La tabla de
 * `REQ-SUB-004` RB-3 dice «aplicar logo y color», no «guardarlos», y SP-3 lo
 * exige explícitamente: en Free se guarda, no se aplica, y al subir de plan se
 * activa **sin volver a configurarlo**.
 *
 * Es la diferencia entre una función que no tienes y un dato que no te dejan
 * escribir, y la segunda haría que subir de plan costara rellenar el
 * formulario otra vez. Por eso `UpdateOrganizationAction` **no** llama a
 * `assertHasFeature()`: quien pregunta por la función es quien pinta, leyendo
 * `hasFeature(Branding)`, y el resultado viaja aquí dentro.
 *
 * Espejo exacto de `OrganizationBrand` en [04 §contratos] y en
 * `resources/js/types/backoffice.ts`.
 */
final readonly class OrganizationBrand
{
    /**
     * Disco donde vive el logo. Público a propósito: lo pinta el catálogo
     * anónimo y una URL firmada por visita sería un coste por nada.
     */
    public const LOGO_DISK = 'public';

    private function __construct(
        public string $name,
        public string $slug,
        public ?string $logoUrl,
        public ?string $brandColor,
        /** `false` en Free: el catálogo muestra el sello «Hecho con Apartar» (`REQ-CAT-022` RB-5). */
        public bool $brandingEnabled,
    ) {}

    /**
     * @param  bool  $brandingEnabled  lo que respondió `LimitEnforcer::hasFeature(Branding)`
     */
    public static function of(Organization $organization, bool $brandingEnabled): self
    {
        return new self(
            name: $organization->name,
            slug: $organization->slug,
            logoUrl: self::logoUrlOf($organization),
            brandColor: $organization->brand_color,
            brandingEnabled: $brandingEnabled,
        );
    }

    /**
     * Forma con la que viaja a las page props.
     *
     * El logo y el color van **aunque `brandingEnabled` sea `false`**: la
     * pantalla de ajustes tiene que enseñar lo que está guardado, incluso
     * —sobre todo— cuando no se está aplicando. Quien está en Free tiene que
     * ver lo que se pierde (`REQ-SUB-004` RB-7).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'logoUrl' => $this->logoUrl,
            'brandColor' => $this->brandColor,
            'brandingEnabled' => $this->brandingEnabled,
        ];
    }

    private static function logoUrlOf(Organization $organization): ?string
    {
        $path = $organization->logo_path;

        return $path === null || $path === '' ? null : Storage::disk(self::LOGO_DISK)->url($path);
    }
}
