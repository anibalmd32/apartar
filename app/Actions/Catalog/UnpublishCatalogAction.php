<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Retirar un catálogo de circulación (`REQ-CAT-022` RB-7, RB-8, RB-9).
 *
 * Vacía `published_at` y ya está: el catálogo vuelve a `Draft` y su URL pasa a
 * dar `404` (`REQ-CAT-021` SP-2). La acción es deliberadamente corta, y lo que
 * tiene de interesante es **todo lo que no hace**.
 *
 * ## No borra nada, y por eso no hay un solo recorrido aquí
 *
 *  - **Los apartados originados en el catálogo siguen igual** (RB-8,
 *    `REQ-LAY-002`). Quien apartó un televisor sigue debiendo sus cuotas y
 *    sigue viendo dónde pagarlas: la campaña se retiró, la deuda no.
 *  - **Las suscripciones no se borran** (RB-9, `REQ-ACC-002` RB-8): quedan
 *    inactivas y reviven si se republica. Ninguna de las dos tablas existe
 *    todavía —`layaways` llega con H4 y `catalog_subscriptions` con `T-073`—,
 *    así que lo que corresponde hacer hoy es **no escribir el recorrido que
 *    las buscaría**. Un `->delete()` adelantado sobre una tabla futura sería
 *    la línea que nadie revisa cuando la tabla por fin llega.
 *  - **El slug no se libera** (SP-7). La fila conserva el suyo y el índice
 *    sigue ocupándolo: su URL circuló, y dársela a otro catálogo mandaría a
 *    esa gente al sitio equivocado en lugar de a un `404` honesto.
 *
 * ## Tampoco toca `is_listed` ni `expires_at`
 *
 * Despublicar no es «apagarlo todo»: es quitar la publicación. Volver a
 * publicar tiene que encontrar la campaña como se dejó —dentro o fuera de la
 * vitrina, con su fecha de fin—, y si la fecha ya pasó, `PublishCatalogAction`
 * lo dirá entonces con su `422` (SP-8) en vez de haberla borrado por su
 * cuenta.
 *
 * ## Y la advertencia no vive aquí
 *
 * RB-7 y `REQ-BOF-024` RB-9 piden advertir **antes de confirmar** que los
 * enlaces difundidos dejarán de funcionar. Eso es de la pantalla, que es quien
 * tiene el botón: una acción que se ejecuta ya no puede advertir de nada.
 */
final class UnpublishCatalogAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Devuelve el catálogo a borrador.
     *
     * Es idempotente y no sobrevive a la suspensión por su cuenta: la ruta ya
     * la bloquea, y aquí no se añade la comprobación de `PublishCatalogAction`
     * a propósito. **Retirar algo de la calle nunca es crear**, y un negocio
     * suspendido que quiera apagar una campaña con precios viejos no debería
     * tener que ponerse al día primero — es lo contrario de lo que
     * `REQ-ORG-008` RB-3 protege.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function handle(Catalog $catalog, User $actor): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        if ($catalog->visibility() === CatalogVisibility::Draft) {
            // Ya lo está: ni columna ni entrada de auditoría. Suele ser el
            // segundo clic de quien no vio el primero.
            return $catalog;
        }

        return DB::transaction(function () use ($catalog, $actor): Catalog {
            $before = [
                'visibility' => $catalog->visibility()->value,
                'published_at' => $catalog->published_at?->toIso8601String(),
            ];

            $catalog->editedBy($actor)->forceFill(['published_at' => null])->save();

            $this->audit(
                AuditEvent::CatalogUnpublished,
                subject: $catalog,
                actor: $actor,
                before: $before,
                after: ['visibility' => $catalog->visibility()->value, 'published_at' => null],
            );

            return $catalog;
        });
    }
}
