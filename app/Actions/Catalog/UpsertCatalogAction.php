<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\User;
use App\Support\UniqueCatalogSlug;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * El alta y la edición de un catálogo (`REQ-BOF-024` RB-1).
 *
 * Lo que escribe: `name`, `slug`, `description` y la portada. Lo que **no**:
 * `is_listed`, `published_at` y `expires_at`, que son de
 * `PublishCatalogAction`. El reparto no es estético — ver allí.
 *
 * ## El enlace lo decide el índice, no una consulta
 *
 * `catalogs.slug` es único en **toda la plataforma** (`REQ-CAT-022` RB-1), y
 * averiguar si un slug está libre exigiría leer los catálogos del negocio de
 * al lado. No se hace: se intenta escribir y, si el índice choca, se
 * desambigua con sufijo en la vuelta siguiente. Todo eso vive en
 * `UniqueCatalogSlug`, con su porqué entero.
 *
 * Por eso el `save()` va **dentro** del intento y no hay transacción alrededor
 * de la fila: el choque de unicidad es el mecanismo, no un fallo, y envolverlo
 * obligaría a abrir y cerrar una transacción por cada sufijo probado.
 *
 * ## Y se cierra con la primera publicación
 *
 * RB-2 y SP-6: tras publicar, el slug es inmutable, y despublicar **no** lo
 * reabre (SP-7). La condición se lee del estado derivado y no de un booleano
 * guardado, así que un catálogo expirado —que sigue teniendo su URL en la
 * calle— cuenta como publicado igual.
 *
 * **No audita**, y es lo que la lista cerrada de `REQ-BOF-005` RB-1 dice: no
 * hay evento de «catálogo creado» ni de «catálogo renombrado». Lo que se
 * audita es la publicación, la despublicación y la expiración, que es lo que
 * cambia qué ve un desconocido.
 */
final class UpsertCatalogAction
{
    /**
     * Carpeta de las portadas dentro del disco público, por organización.
     */
    private const COVER_DIRECTORY = 'catalogos';

    /**
     * El disco de las portadas.
     *
     * El mismo que el del logo del negocio y por lo mismo: lo pinta el
     * catálogo anónimo, así que firmar una URL por visita sería un coste sin
     * contrapartida.
     */
    public const COVER_DISK = 'public';

    public function __construct(
        private readonly TenantContext $context,
        private readonly UniqueCatalogSlug $slugs,
    ) {}

    /**
     * Crea el catálogo o guarda los cambios del que ya existe.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws SlugLockedException si se pide cambiar el enlace de uno ya publicado (SP-6)
     */
    public function handle(CatalogData $data, User $actor, ?Catalog $catalog = null): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        if (! $catalog instanceof Catalog) {
            return $this->create($data, $actor);
        }

        return $this->update($catalog->editedBy($actor), $data);
    }

    /**
     * El alta, con el enlace sugerido del nombre (RB-1).
     *
     * Nace en borrador: `published_at` nulo es lo que hace `Draft`, y
     * publicarlo es un paso aparte con sus seis requisitos delante. Un alta
     * que publicara de golpe se saltaría los seis.
     */
    private function create(CatalogData $data, User $actor): Catalog
    {
        return $this->slugs->generate($data->slug ?? $data->name, function (string $slug) use ($data, $actor): Catalog {
            $catalog = (new Catalog)->editedBy($actor)->forceFill([
                'name' => $data->name,
                'slug' => $slug,
                'description' => $data->description,
                'cover_path' => $data->cover instanceof UploadedFile ? $this->storeCover($data->cover) : null,
                'is_listed' => true,
                'published_at' => null,
                'expires_at' => null,
            ]);

            $catalog->save();

            return $catalog;
        });
    }

    /**
     * La edición.
     *
     * El enlace se resuelve **antes** de escribir nada: un `422` sobre el slug
     * no puede dejar el renombrado aplicado, porque entonces la pantalla diría
     * «no se guardó» enseñando un nombre que sí se guardó. Es la misma
     * cautela de `UpsertCategoryAction`.
     */
    private function update(Catalog $catalog, CatalogData $data): Catalog
    {
        $attributes = [
            'name' => $data->name,
            'description' => $data->description,
        ];

        if ($data->cover instanceof UploadedFile) {
            $attributes['cover_path'] = $this->storeCover($data->cover, $catalog->cover_path);
        }

        $requested = $this->requestedSlug($catalog, $data->slug);

        if ($requested === null) {
            $catalog->forceFill($attributes)->save();

            return $catalog;
        }

        return $this->slugs->generate($requested, function (string $slug) use ($catalog, $attributes): Catalog {
            $catalog->forceFill($attributes + ['slug' => $slug])->save();

            return $catalog;
        });
    }

    /**
     * El enlace que hay que reescribir, o `null` si no se toca.
     *
     * Vacío o igual al que ya tiene significa «no lo cambies», y eso importa
     * más de lo que parece: un catálogo publicado sigue pudiendo cambiar su
     * nombre, su descripción y su portada, y si el formulario reenviara el
     * slug de siempre y eso contara como intento, el cerrojo de RB-2 le
     * bloquearía el formulario entero.
     *
     * @throws SlugLockedException si el catálogo ya se publicó (SP-6)
     */
    private function requestedSlug(Catalog $catalog, ?string $slug): ?string
    {
        if ($slug === null || $slug === '' || $slug === $catalog->slug) {
            return null;
        }

        if ($catalog->visibility() !== CatalogVisibility::Draft) {
            throw SlugLockedException::catalogIsPublished($catalog->slug);
        }

        return $slug;
    }

    /**
     * Guarda la portada y devuelve su ruta.
     *
     * La anterior se borra: no la referencia nadie más, y dejarla haría crecer
     * el almacenamiento —que es una métrica de plan (`REQ-SUB-003`)— con
     * archivos que ya nadie puede volver a ver.
     */
    private function storeCover(UploadedFile $cover, ?string $previous = null): string
    {
        $organizationId = $this->context->organizationId();

        $path = (string) $cover->store(self::COVER_DIRECTORY.'/'.$organizationId, self::COVER_DISK);

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            Storage::disk(self::COVER_DISK)->delete($previous);
        }

        return $path;
    }
}
