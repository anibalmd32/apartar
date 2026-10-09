<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\CatalogNotPublishableException;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\PaymentAccount;
use App\Models\User;
use App\Support\Auditing\LogsSensitiveActivity;
use App\Support\ModelState;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Publicar un catálogo: el momento en que un enlace empieza a circular
 * (`REQ-CAT-022` RB-4, `REQ-BOF-024` RB-3).
 *
 * Es el punto al que apuntaba todo H2. Antes de aquí el negocio tiene
 * inventario; después tiene una URL que puede pegar en WhatsApp, y todo lo que
 * esté mal se ve en el teléfono de un cliente.
 *
 * ## Los seis requisitos de RB-4, y dónde está el que falta
 *
 *  1. al menos un producto **publicado** dentro — SP-1 · aquí
 *  2. al menos una cuenta de cobro activa — SP-2 · aquí
 *  3. `expires_at` vacía o futura — SP-5 y SP-8 · aquí
 *  4. cupo disponible en el límite `catalogs` — SP-3 · **de `T-067`**
 *  5. email del `owner` verificado — SP-4 · aquí
 *  6. organización no suspendida — SP-9 · aquí
 *
 * **El cuarto no está y no se simula.** Desde `T-066` publicar **mueve** el
 * contador `catalogs` —uno más al pasar de borrador o caducado a publicado—,
 * pero no lo **comprueba**: insertar las llamadas es de `T-067`. Lo que sí
 * está es el sitio: una línea `assertCanCreate(UsageMetric::Catalogs)` dentro
 * de la transacción de `apply()`, que es donde el contador se lee bloqueado.
 *
 * ## El orden de las comprobaciones es parte de la respuesta
 *
 * La suspensión va **primero** y es `403`, no `422`: decirle a quien está
 * suspendido que «le falta una cuenta de cobro» lo mandaría a crear una cuenta
 * en vez de a pagar lo que debe. Es el mismo criterio por el que
 * `CreationSuspendedException` existe aparte del límite de plan.
 *
 * Los demás se evalúan **todos** y viajan juntos: quien publica con el
 * catálogo vacío y sin cuenta de cobro arregla las dos cosas de una pasada, y
 * una lista que nombre solo el primer fallo lo manda a buscar dos veces.
 *
 * ## Y es el único escritor de las tres columnas de publicación
 *
 * `published_at`, `is_listed` y `expires_at` se escriben aquí y en
 * `UnpublishCatalogAction`, y en ningún otro sitio: el formulario del catálogo
 * guarda nombre, descripción, enlace y portada, y nada más. Es el reparto que
 * `T-024` hizo con `catalog_product` y `T-030` con `products.status`, por el
 * mismo motivo: con dos caminos hacia la misma columna, el segundo es el que
 * se olvida de los seis requisitos.
 *
 * De ahí que **cambiar la caducidad o sacar el catálogo de la vitrina sea
 * volver a pulsar publicar**, con los requisitos delante otra vez. Tiene
 * sentido más allá de la simetría: mover la fecha de una campaña viva es
 * exactamente cuando conviene volver a comprobar que sigue teniendo productos
 * publicados y a dónde cobrar.
 *
 * ## No hay job de expiración, y no puede haberlo (RB-6)
 *
 * `expires_at` se guarda y ya está. Quien decide que un catálogo caducó es
 * `Catalog::visibility()` contra el reloj, en cada lectura. Un job que
 * «expirara» a su hora sería un segundo mecanismo capaz de no correr, y el
 * fallo sería una campaña vendiendo a precios de septiembre el 3 de octubre.
 */
final class PublishCatalogAction
{
    use LogsSensitiveActivity;

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly UsageCalculator $usage,
    ) {}

    /**
     * Publica el catálogo, o actualiza su visibilidad y su caducidad.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws CreationSuspendedException si la organización está suspendida (SP-9)
     * @throws CatalogNotPublishableException con la lista de lo que falta (SP-1, SP-2, SP-4, SP-5, SP-8)
     */
    public function handle(Catalog $catalog, PublishCatalogData $data, User $actor): Catalog
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $this->assertOrganizationIsActive();

        $missing = $this->missingToPublish($catalog, $data);

        if ($missing !== []) {
            throw CatalogNotPublishableException::of($catalog, $missing);
        }

        return $this->apply($catalog, $data, $actor);
    }

    /**
     * Los requisitos de RB-4 que el catálogo **no** cumple hoy, sin intentar
     * publicar nada.
     *
     * Existe para que la pantalla pueda explicarlo **antes** de que se pulse
     * el botón (`REQ-BOF-024` RB-4), y es una lectura: no autoriza, no escribe
     * y no decide. Quien manda sigue siendo `handle()`, que lo vuelve a
     * comprobar todo — entre pintar el formulario y pulsar publicar puede
     * haberse desactivado la última cuenta de cobro.
     *
     * Que la lista salga de **la misma función** que la usa para rechazar es
     * el punto: con dos censos de los mismos requisitos, el del aviso es el
     * que se queda viejo, y el negocio ve la pantalla en verde y el `422`
     * después.
     *
     * La caducidad que se evalúa es la que el catálogo tiene guardada, que es
     * lo que SP-8 necesita: un catálogo expirado enseña su pendiente antes de
     * que nadie vuelva a pulsar publicar.
     *
     * @return array<string, string> campo → qué corregir; vacío si está listo
     */
    public function blockersFor(Catalog $catalog): array
    {
        $expiresAt = $catalog->expires_at;

        return $this->missingToPublish($catalog, new PublishCatalogData(
            isListed: $catalog->is_listed,
            expiresAt: $expiresAt === null ? null : CarbonImmutable::instance($expiresAt),
        ));
    }

    /**
     * Requisito 6 · la organización no puede estar suspendida (SP-9).
     *
     * `EnsureOrganizationIsActive` ya devuelve `403` en la ruta, que no
     * declara `during-suspension`. Esto no es redundante: la regla la pide
     * RB-4 **en la acción**, y un comando o un job que publicara sin pasar por
     * el middleware se la saltaría entera. Es la misma cautela con la que
     * `LimitEnforcer` es el único punto de decisión del cupo.
     *
     * Se compara contra `Active` y no contra `Suspended` para fallar cerrado:
     * un estado nuevo en el enum tendría que decidir explícitamente que
     * permite publicar.
     *
     * @throws CreationSuspendedException
     */
    private function assertOrganizationIsActive(): void
    {
        if ($this->context->organization()->status === OrganizationStatus::Active) {
            return;
        }

        throw CreationSuspendedException::whileCreating(UsageMetric::Catalogs);
    }

    /**
     * Qué le falta al catálogo para poder publicarse (RB-4).
     *
     * Las cuatro que son datos suyos, **todas evaluadas** aunque la primera ya
     * haya fallado. El mapa es campo → qué corregir, así que cada frase
     * aterriza junto a su control y el resumen las nombra todas.
     *
     * @return array<string, string> campo → qué corregir; vacío si está listo
     */
    private function missingToPublish(Catalog $catalog, PublishCatalogData $data): array
    {
        $missing = [];

        // SP-1 · **publicado**, no «dentro del catálogo». Un borrador o un
        // programado para el jueves cuentan para la composición (`REQ-CAT-023`
        // RB-5) y no para esto: publicar con solo esos daría una URL que
        // circula enseñando una página vacía. La condición se lee de
        // `scopePublished()`, que es la misma que decide qué se renderiza.
        if (! $catalog->products()->published()->exists()) {
            $missing['products'] = 'Añade al menos un producto publicado. Los borradores y los programados pueden '
                .'estar dentro, pero no se muestran: el enlace abriría un catálogo vacío.';
        }

        // SP-2 · `REQ-ORG-007` RB-4. Sin cuenta de cobro activa, el cliente
        // llega hasta «apartar» y no tiene a dónde pagar.
        if (! PaymentAccount::query()->where('is_active', true)->exists()) {
            $missing['payment_accounts'] = 'Activa al menos una cuenta de cobro: es a dónde le dirás a tu cliente '
                .'que pague, y sin ella el catálogo no puede cerrar una venta.';
        }

        // SP-5 y SP-8 · la misma comprobación resuelve las dos. Publicar con
        // una fecha ya pasada y republicar un expirado sin moverla acaban en
        // el mismo sitio: un catálogo que nacería caducado, con su URL dando
        // `410` desde el primer minuto.
        if ($data->expiresAt !== null && $data->expiresAt->isPast()) {
            $missing['expires_at'] = 'La fecha de caducidad ya pasó: el enlace diría que la campaña terminó desde '
                .'el primer momento. Muévela al futuro, o déjala vacía para que el catálogo no caduque.';
        }

        // SP-4 · `REQ-ORG-001` RB-5. El resto del backoffice funciona sin
        // verificar; esto no, porque es el paso en que el negocio empieza a
        // recibir gente de fuera.
        if (! $this->ownerEmailIsVerified()) {
            $missing['owner_email'] = 'Falta verificar el correo del dueño del negocio: es la dirección con la que '
                .'se recupera la cuenta y a la que llegan los avisos. Abre el enlace que te enviamos al '
                .'registrarte, o pide uno nuevo.';
        }

        return $missing;
    }

    /**
     * Si algún `owner` activo tiene su correo verificado (SP-4).
     *
     * Se pregunta por el **dueño** y no por quien pulsa el botón, que puede
     * ser un empleado: lo que RB-5 quiere comprobar es que la dirección con la
     * que se recupera el negocio existe de verdad, y esa es la del owner.
     *
     * Basta con uno: un negocio con dos dueños en el que uno ha verificado ya
     * tiene una dirección demostrada, y exigir las dos bloquearía la campaña
     * por el correo de alguien que a lo mejor lleva meses sin entrar. La
     * consulta va por el global scope del tenant, como la de `LastOwnerGuard`.
     */
    private function ownerEmailIsVerified(): bool
    {
        return User::query()
            ->where('role', OrganizationRole::Owner)
            ->whereNull('disabled_at')
            ->whereNotNull('email_verified_at')
            ->exists();
    }

    /**
     * Escribe las tres columnas y deja el rastro (RB-10).
     *
     * `published_at` se fija **solo si estaba vacía**: la fecha dice desde
     * cuándo circula el enlace, y sacar el catálogo de la vitrina o mover su
     * caducidad no reinicia esa circulación. Despublicar sí la vacía, así que
     * republicar después estrena fecha — que es correcto, porque entre medias
     * la URL estuvo dando `404`.
     *
     * Dos entradas y no una: `catalog.published` responde «¿quién lo puso en
     * la calle y con qué visibilidad?» y `catalog.expiration_scheduled`
     * responde «¿quién movió la fecha de fin?», que es la pregunta que aparece
     * cuando una campaña se apaga antes de tiempo. Las dos están declaradas en
     * `AuditEvent` con esta clase como emisora.
     *
     * **Si no cambia nada, no se escribe nada**, ni columna ni auditoría: es
     * el segundo clic de quien no vio el primero, y llenar el registro de
     * cambios que no ocurrieron es peor que dejarlo incompleto, porque se lee
     * igual de bien.
     */
    private function apply(Catalog $catalog, PublishCatalogData $data, User $actor): Catalog
    {
        return DB::transaction(function () use ($catalog, $data, $actor): Catalog {
            // Lo primero, el contador `catalogs` recontado y bloqueado: los
            // catálogos caducan solos y el contador puede llevar uno que ya no
            // cuenta (`UsageCalculator::recountLocked()`). Antes que la fila
            // del catálogo, para que dos publicaciones se serialicen aquí.
            $this->usage->recountLocked(UsageMetric::Catalogs);

            // El catálogo, releído con bloqueo: si ya contaba o no es lo que
            // decide cuánto se mueve el cupo `catalogs` (`REQ-SUB-003` RB-3),
            // y dos publicaciones simultáneas del mismo borrador que lo
            // leyeran del modelo de su petición sumarían dos por uno.
            ModelState::adopt($catalog, Catalog::query()->whereKey($catalog->getKey())->lockForUpdate()->firstOrFail()->getAttributes());

            $before = $this->snapshotOf($catalog);
            $counted = $catalog->visibility()->countsTowardPlanLimit();

            // `T-067` inserta aquí su línea: el cupo se comprueba con el
            // contador bloqueado dentro de esta misma transacción, que es lo
            // que impide que dos publicaciones simultáneas gasten un solo
            // hueco ([05 §5]). Solo cuando `$counted` es falso: cambiar la
            // fecha de un catálogo que ya está publicado no ocupa nada nuevo.
            $catalog->editedBy($actor)->forceFill([
                'is_listed' => $data->isListed,
                'expires_at' => $data->expiresAt,
                'published_at' => $catalog->published_at ?? now(),
            ])->save();

            $after = $this->snapshotOf($catalog);

            if ($after === $before) {
                return $catalog;
            }

            // De borrador o caducado a publicado, uno más; de publicado a
            // publicado con otra fecha, cero. Sobre la cifra recién recontada,
            // así que un caducado que se republica no cuenta dos veces.
            $this->limits->adjust(
                UsageMetric::Catalogs,
                (int) $catalog->visibility()->countsTowardPlanLimit() - (int) $counted,
            );

            $this->audit(
                AuditEvent::CatalogPublished,
                subject: $catalog,
                actor: $actor,
                before: $before,
                after: $after,
            );

            if ($before['expires_at'] !== $after['expires_at']) {
                $this->audit(
                    AuditEvent::CatalogExpirationScheduled,
                    subject: $catalog,
                    actor: $actor,
                    before: ['expires_at' => $before['expires_at']],
                    after: ['expires_at' => $after['expires_at']],
                );
            }

            return $catalog;
        });
    }

    /**
     * Las tres columnas de publicación tal como caben en el JSON de la
     * auditoría.
     *
     * El estado derivado viaja con ellas porque es lo que se lee de un
     * vistazo: «listed → unlisted» explica en una línea lo que
     * `is_listed: true → false` obliga a interpretar.
     *
     * @return array{visibility: string, is_listed: bool, published_at: string|null, expires_at: string|null}
     */
    private function snapshotOf(Catalog $catalog): array
    {
        return [
            'visibility' => $catalog->visibility()->value,
            'is_listed' => $catalog->is_listed,
            'published_at' => $catalog->published_at?->toIso8601String(),
            'expires_at' => $catalog->expires_at?->toIso8601String(),
        ];
    }
}
