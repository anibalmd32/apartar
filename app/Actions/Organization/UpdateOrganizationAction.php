<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\PublishedCatalogs;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\SlugLockedException;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ReservedSlugs;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * La identidad del negocio: cómo se llama, dónde vive y cómo se ve
 * (`REQ-ORG-006`).
 *
 * ## Branding se guarda siempre
 *
 * **Esta acción no llama a `assertHasFeature()`**, y es deliberado hasta el
 * punto de tener su propia regla (RB-5b): bloquear el guardado rompería SP-3,
 * que exige que en Free el branding **se guarde y no se aplique**, y que al
 * subir de plan **se active sin volver a configurarlo**. La tabla de
 * `REQ-SUB-004` RB-3 dice «aplicar logo y color», no «guardarlos». Quien
 * pregunta por la función es quien pinta, con la lectura `hasFeature()`, y la
 * respuesta viaja dentro de `OrganizationBrand`.
 *
 * ## El slug se cierra, no se libera
 *
 * SP-5: con catálogos publicados **no se cambia**, porque la vitrina
 * `/{org-slug}` ya está circulando por WhatsApp y en alguna bio de Instagram.
 * La comprobación la responde `PublishedCatalogs`, que hoy dice que no hay
 * ninguno porque `catalogs` llega con `T-030`: la regla está escrita con su
 * forma definitiva y lo único que le falta es la tabla.
 *
 * Y cuando sí se puede cambiar, pasa por lo mismo que en el registro:
 * `ReservedSlugs` (SP-8) y el índice único, que es lo único que cierra la
 * ventana entre comprobar y escribir.
 */
final class UpdateOrganizationAction
{
    use LogsSensitiveActivity;

    /**
     * Carpeta del logo dentro del disco público, por organización.
     */
    private const LOGO_DIRECTORY = 'logos';

    /**
     * Campos de identidad que viajan al «antes y después» de la auditoría.
     *
     * `logo_path` entra y el archivo **no**: RB-5 prohíbe el contenido de
     * archivos en `properties`, y lo que hace falta para responder «¿quién
     * cambió el logo?» es saber que la ruta cambió, no llevarse la imagen
     * dentro del registro.
     *
     * @var list<string>
     */
    private const AUDITED_IDENTITY = [
        'name',
        'slug',
        'document',
        'phone',
        'timezone',
        'brand_color',
        'storefront_enabled',
        'logo_path',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly PublishedCatalogs $publishedCatalogs,
    ) {}

    /**
     * @param  UpdateOrganizationData  $data  identidad ya normalizada
     * @param  User  $actor  quien guarda
     *
     * @throws AuthorizationException si el actor no gobierna la identidad (SP-2)
     * @throws SlugLockedException si el slug ya no se puede cambiar (SP-5)
     * @throws SlugTakenException si el slug pedido está ocupado o reservado (SP-8)
     */
    public function handle(UpdateOrganizationData $data, User $actor): Organization
    {
        $organization = $this->context->organization();

        Gate::forUser($actor)->authorize('manageBranding', $organization);

        $slug = $this->resolveSlug($organization, $data->slug);

        $attributes = [
            'name' => $data->name,
            'slug' => $slug,
            'document' => $data->document,
            'phone' => $data->phone,
            'timezone' => $data->timezone,
            'brand_color' => $data->normalisedBrandColor(),
            'storefront_enabled' => $data->storefrontEnabled,
        ];

        if ($data->logo instanceof UploadedFile) {
            $attributes['logo_path'] = $this->storeLogo($organization, $data->logo);
        }

        $before = $this->auditedIdentityOf($organization);

        try {
            DB::transaction(function () use ($organization, $attributes, $before, $actor): void {
                $organization->forceFill($attributes)->save();

                // RB-1 · «cambios de ajustes, identidad y branding». El enlace
                // de la vitrina es el que más importa de los ocho campos: es
                // público, circula por WhatsApp, y cuando alguien pregunte por
                // qué dejó de funcionar la respuesta tiene que ser fechada y
                // con nombre.
                $this->audit(
                    AuditEvent::IdentityUpdated,
                    subject: $organization,
                    actor: $actor,
                    before: $before,
                    after: $this->auditedIdentityOf($organization),
                );
            });
        } catch (UniqueConstraintViolationException) {
            // Otro negocio reclamó ese enlace entre la comprobación y el
            // `UPDATE`. Es la misma carrera que resuelve el registro, y la
            // misma respuesta: la variante libre, que es lo único accionable.
            throw SlugTakenException::suggesting($slug, $slug.'-2');
        }

        return $organization;
    }

    /**
     * La identidad tal como la necesita la pantalla, con el branding resuelto.
     *
     * `hasFeature()` y no `assertHasFeature()`: aquí se **lee** si el plan
     * aplica el branding, no se exige. Es lo que permite que la pantalla de
     * ajustes enseñe el logo guardado con el aviso de que no se está
     * aplicando, en vez de esconder el control (`REQ-SUB-004` RB-7).
     */
    public function brandOf(Organization $organization): OrganizationBrand
    {
        return OrganizationBrand::of($organization, $this->limits->hasFeature(PlanFeature::Branding));
    }

    /**
     * Los campos de identidad que se auditan, tal como están ahora.
     *
     * @return array<string, mixed>
     */
    private function auditedIdentityOf(Organization $organization): array
    {
        $identity = [];

        foreach (self::AUDITED_IDENTITY as $attribute) {
            $identity[$attribute] = $organization->getAttribute($attribute);
        }

        return $identity;
    }

    /**
     * El slug con el que queda la organización.
     *
     * @throws SlugLockedException
     * @throws SlugTakenException
     */
    private function resolveSlug(Organization $organization, string $requested): string
    {
        $slug = Str::slug($requested);

        if ($slug === '' || $slug === $organization->slug) {
            // No cambiarlo no exige poder cambiarlo: un negocio con catálogos
            // publicados sigue pudiendo editar su nombre, su teléfono o su
            // color sin que el slug le bloquee el formulario entero.
            return $organization->slug;
        }

        if ($this->publishedCatalogs->exist()) {
            throw SlugLockedException::hasPublishedCatalogs($organization->slug);
        }

        if (ReservedSlugs::contains($slug)) {
            throw SlugTakenException::reserved($slug, $slug.'-2');
        }

        if (Organization::query()->where('slug', $slug)->whereKeyNot($organization->getKey())->exists()) {
            throw SlugTakenException::suggesting($slug, $slug.'-2');
        }

        return $slug;
    }

    /**
     * Guarda el logo y devuelve su ruta.
     *
     * Va en el disco **público** y bajo una carpeta por organización: lo pinta
     * el catálogo anónimo, así que firmar una URL por visita sería un coste
     * sin contrapartida —no hay nada privado en el logo de un negocio que
     * quiere que lo vean—.
     *
     * El anterior se borra: un logo sustituido no lo referencia nadie más, y
     * dejarlo haría crecer el almacenamiento —que es una métrica de plan
     * (`REQ-SUB-003`)— con archivos que nadie puede volver a ver.
     */
    private function storeLogo(Organization $organization, UploadedFile $logo): string
    {
        $disk = Storage::disk(OrganizationBrand::LOGO_DISK);

        $path = $logo->store(self::LOGO_DIRECTORY.'/'.$organization->getKey(), OrganizationBrand::LOGO_DISK);

        $previous = $organization->logo_path;

        if (is_string($previous) && $previous !== '' && $previous !== $path) {
            $disk->delete($previous);
        }

        return (string) $path;
    }
}
