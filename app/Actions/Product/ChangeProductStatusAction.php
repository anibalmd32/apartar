<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\IncompleteProductException;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\User;
use App\Rules\CoherentFinancingTerms;
use App\Support\Auditing\LogsSensitiveActivity;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Las transiciones de estado de un producto (`REQ-CAT-007`).
 *
 * **Aquí se decide qué ve un desconocido y qué no.** Todo lo que cambia
 * `products.status` pasa por esta clase: `T-027` escribió el archivado con su
 * `transitionTo()` privado, y `T-030` añade delante la matriz, la completitud
 * de `REQ-BOF-020` RB-5 y las otras tres transiciones. `UpsertProductAction`
 * no toca la columna —crea en `draft` y al editar la deja como está—, así que
 * no hay un segundo camino.
 *
 * ## La matriz, y el único paso que se niega
 *
 * ```
 *  draft      → published   publicar, con la lista de RB-5 cumplida
 *  draft      → archived    archivar
 *  published  → draft       despublicar
 *  published  → archived    archivar
 *  archived   → draft       restaurar
 *  archived   → published   ✗ SP-4
 * ```
 *
 * El paso que falta es el importante: **restaurar devuelve a `draft`, nunca
 * directamente a `published`** (SP-4). Un archivado que volviera publicado se
 * cuela de golpe en los catálogos donde seguía —su relación se conserva
 * (RB-7)— con precio, existencias y fotos que nadie ha revisado desde el día
 * en que se retiró. La parada en borrador es lo que obliga a mirarlo.
 *
 * Repetir un paso ya aplicado **no es un error**: suele ser el segundo clic de
 * quien no vio el primero. No escribe columna ni entrada de auditoría, porque
 * no ha cambiado nada que auditar.
 *
 * ## Publicar no programa nada
 *
 * `published_at` futuro es publicación programada (RB-5, SP-5) y **se evalúa
 * en la consulta**: `Product::scopePublished()` filtra por
 * `published_at <= now()` y el backoffice marca la fila «Programado». Aquí no
 * hay job ni cola. Un job que «publicara» a la hora sería un segundo
 * mecanismo que puede no correr, y entonces el producto se queda invisible sin
 * que nada falle.
 *
 * ## Archivar no es borrar, y por eso no rompe nada
 *
 * El producto conserva su fila (RB-2) porque los apartados vigentes operan
 * sobre su *snapshot* y el historial tiene que sobrevivir; conserva también su
 * relación con los catálogos (RB-7), que simplemente dejan de renderizarlo, de
 * modo que desarchivarlo lo devuelve sin recomponer nada. Por eso aquí no hay
 * ningún `delete()` ni ningún recorrido que desate pivotes: archivar cambia
 * una columna y no toca nada más. Tampoco toca los apartados vivos (RB-3), y
 * por eso no hay ninguna consulta que los busque.
 *
 * ## Lo que no está y tiene dueño
 *
 * **El cupo del plan de RB-8 no se ajusta aquí.** Archivar libera y restaurar
 * reclama (SP-4), pero quien mide la métrica `products` es `T-066` y quien
 * inserta las llamadas a `LimitEnforcer` es `T-067`. Hoy esa métrica responde
 * «sin límite», así que una llamada adelantada haría cumplir una ficción.
 *
 * **El `404` de SP-1 tampoco.** Lo de esta clase es el mecanismo —el estado— y
 * la consulta que lo respeta; la respuesta HTTP de un `draft` o un `archived`
 * pedido por URL directa la prueban `T-033` y `T-035`, que son las que tienen
 * página pública que pedir.
 */
final class ChangeProductStatusAction
{
    use LogsSensitiveActivity;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Publica el producto, si está completo (RB-5, SP-3, SP-5).
     *
     * `$at` es la fecha desde la cual se considera publicado: `now()` cuando
     * no se pide otra, y **una futura es publicación programada** — el estado
     * pasa a `published` igualmente y quien lo mantiene invisible hasta la
     * hora es `scopePublished()`. Una fecha pasada publica en el acto, que es
     * lo mismo que no mandar ninguna.
     *
     * La fecha se reescribe en cada publicación: un producto que se
     * despublicó y se vuelve a publicar estrena fecha, porque la anterior
     * describía una circulación que se interrumpió.
     *
     * @param  DateTimeInterface|null  $at  desde cuándo se considera publicado; `now()` si no se dice
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     * @throws IncompleteProductException si le falta algo de RB-5
     * @throws ValidationException si el producto está archivado (SP-4)
     */
    public function publish(Product $product, User $actor, ?DateTimeInterface $at = null): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        $this->assertStepIsAllowed($product, ProductStatus::Published);

        if ($product->status === ProductStatus::Published) {
            // Ya lo está: ni se recomprueba la completitud ni se mueve la
            // fecha. Volver a pulsar «Publicar» no puede adelantar ni retrasar
            // una publicación programada.
            return $product;
        }

        $this->assertIsComplete($product);

        return $this->transitionTo($product, ProductStatus::Published, $actor, [
            'published_at' => $at ?? now(),
        ]);
    }

    /**
     * Devuelve a borrador un producto publicado: deja de verse y vuelve a
     * poder prepararse.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function unpublish(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->backToDraft($product, $actor);
    }

    /**
     * Saca al producto del archivo y lo deja en **borrador** (SP-4).
     *
     * Nunca en `published`, y esa es toda la regla: el producto sigue en los
     * catálogos donde estaba (RB-7), así que volver publicado lo devolvería a
     * la venta sin que nadie hubiera mirado su precio, sus existencias ni sus
     * fotos desde el día en que se retiró. Publicarlo después es otro paso,
     * con la lista de RB-5 delante.
     *
     * Es el mismo paso que `unpublish()` —los dos escriben `draft`— y son dos
     * nombres porque son dos botones, dos rutas y dos mensajes distintos:
     * fundirlos obligaría a la pantalla a decidir cuál de los dos está
     * pulsando.
     *
     * **Lo que todavía no hace es reclamar cupo del plan**, la otra mitad de
     * SP-4: eso es de `T-067`.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function restore(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->backToDraft($product, $actor);
    }

    /**
     * Archiva el producto: deja de verse y deja de poder apartarse (RB-10).
     *
     * Es idempotente. Archivar lo ya archivado no es un error —suele ser el
     * segundo clic de alguien que no vio el primero— y tampoco escribe una
     * entrada de auditoría, porque no ha cambiado nada que auditar.
     *
     * @throws AuthorizationException si el actor no mantiene el inventario
     */
    public function archive(Product $product, User $actor): Product
    {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        return $this->transitionTo($product, ProductStatus::Archived, $actor);
    }

    /**
     * El paso a `draft`, que comparten despublicar y restaurar.
     *
     * Vacía `published_at` a la vez que escribe el estado: un borrador con
     * fecha de publicación es una fila que se contradice, y dejarla puesta
     * haría que una publicación programada que alguien canceló volviera a
     * leerse como programada en el backoffice.
     */
    private function backToDraft(Product $product, User $actor): Product
    {
        return $this->transitionTo($product, ProductStatus::Draft, $actor, ['published_at' => null]);
    }

    /**
     * Comprueba que el paso pedido está en la matriz.
     *
     * Quedarse donde se está siempre vale: es la idempotencia de los cuatro
     * métodos, y quien la resuelve sin escribir nada es `transitionTo()`.
     *
     * Hoy el único paso que se niega es `archived → published` (SP-4), así que
     * el mensaje es concreto en vez de genérico: decir «transición no
     * permitida» dejaría a quien lo lee sin saber que **sí** hay un camino, y
     * ese camino es restaurar primero.
     *
     * Es `ValidationException` y no un tipo propio por lo mismo que el SKU
     * duplicado de `UpsertProductAction`: lo que viaja es una frase junto a un
     * campo, no una estructura que la pantalla tenga que componer. El `422` lo
     * pone Laravel.
     *
     * @throws ValidationException si el paso no está en la matriz
     */
    private function assertStepIsAllowed(Product $product, ProductStatus $target): void
    {
        if ($product->status === $target || in_array($target, self::reachableFrom($product->status), true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => 'Un producto archivado no se publica de golpe: restáuralo primero —vuelve a borrador— y '
                .'revisa precio, existencias y fotos antes de que vuelva a verse. Sigue en los catálogos donde '
                .'estaba, así que se publicaría en todos ellos a la vez.',
        ]);
    }

    /**
     * A qué estados se puede pasar desde cada uno (`REQ-CAT-007` RB-1, SP-4).
     *
     * @return list<ProductStatus>
     */
    private static function reachableFrom(ProductStatus $status): array
    {
        return match ($status) {
            ProductStatus::Draft => [ProductStatus::Published, ProductStatus::Archived],
            ProductStatus::Published => [ProductStatus::Draft, ProductStatus::Archived],
            // Solo a borrador (SP-4). El salto directo a `published` es el que
            // esta matriz existe para negar.
            ProductStatus::Archived => [ProductStatus::Draft],
        };
    }

    /**
     * Las cuatro condiciones de RB-5, enteras y aquí (SP-3).
     *
     * @throws IncompleteProductException con la lista de lo que falta
     */
    private function assertIsComplete(Product $product): void
    {
        $missing = $this->missingToPublish($product);

        if ($missing !== []) {
            throw IncompleteProductException::of($product, $missing);
        }
    }

    /**
     * Qué le falta al producto para poder publicarse (`REQ-BOF-020` RB-5).
     *
     * Las cuatro, en un solo sitio: **al menos una imagen, categoría asignada,
     * descripción no vacía y condiciones financieras válidas**. Un `draft`
     * puede estar incompleto por definición —`category_id` es nullable
     * justamente por eso—, así que esto no repite lo que ya validó el
     * formulario: se pregunta por **el estado actual de la fila**, que es lo
     * único que se va a publicar.
     *
     * Ninguna de las dos preguntas se reescribe aquí. `hasImages()` es de
     * `T-029` y contesta lo mismo que su SP-4 —no dejar sin fotos un producto
     * ya publicado—; las condiciones de pago son las seis reglas de
     * `CoherentFinancingTerms`, la misma lista que atraviesa el formulario.
     * Dos censos de las mismas reglas coinciden hasta el día en que uno cambia.
     *
     * @return array<string, string> campo → qué corregir; vacío si está listo
     */
    private function missingToPublish(Product $product): array
    {
        $missing = [];

        if (! $product->hasImages()) {
            $missing['images'] = 'Sube al menos una foto: una ficha sin imagen no vende, y el catálogo la '
                .'pintaría como un hueco.';
        }

        if ($product->category_id === null) {
            $missing['category_id'] = 'Elige una categoría: es por donde tus clientes filtran dentro del catálogo.';
        }

        if (trim($product->description ?? '') === '') {
            $missing['description'] = 'Escribe la descripción: es lo que lee quien está decidiendo si lo aparta.';
        }

        $violations = CoherentFinancingTerms::violationsIn(
            $product->price_cents,
            $product->compare_at_price_cents,
            $product->down_payment_cents,
            $product->installments_count,
            $product->frequency_days,
            $product->stock,
        );

        foreach ($violations as $field => $message) {
            // Traducido por la propia regla: sus claves son las del modelo
            // —`down_payment_cents`— y el formulario tiene otras. Una segunda
            // tabla de equivalencias aquí sería la que se quedaría vieja.
            $missing[CoherentFinancingTerms::formFieldFor($field)] = $message;
        }

        return $missing;
    }

    /**
     * Escribe el estado nuevo y deja su rastro.
     *
     * Es el punto por el que pasan las cuatro transiciones, así que el
     * `audit()` de `ProductStatusChanged` está escrito **una sola vez para
     * todas** (`REQ-CAT-007` RB-9). Añadir un evento por transición nueva
     * haría crecer la lista cerrada de `REQ-BOF-005` RB-1 sin tocar la spec.
     *
     * `$extra` son las columnas que el paso escribe **además** del estado
     * —hoy solo `published_at`— y van en el mismo `save()` y en la misma
     * entrada: separarlas daría dos escrituras para un solo cambio, y un
     * intervalo en el que la fila diría «publicado» sin fecha desde la que
     * estarlo.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transitionTo(Product $product, ProductStatus $status, User $actor, array $extra = []): Product
    {
        if ($product->status === $status) {
            return $product;
        }

        return DB::transaction(function () use ($product, $status, $actor, $extra): Product {
            $columns = array_keys($extra);

            $before = ['status' => $product->status->value] + $this->valuesOf($product, $columns);

            $product->forceFill(['status' => $status] + $extra)->save();

            $this->audit(
                AuditEvent::ProductStatusChanged,
                subject: $product,
                actor: $actor,
                before: $before,
                after: ['status' => $status->value] + $this->valuesOf($product, $columns),
            );

            return $product;
        });
    }

    /**
     * Las columnas del paso, como caben en el JSON de la auditoría.
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function valuesOf(Product $product, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $value = $product->getAttribute($column);

            $values[$column] = $value instanceof CarbonInterface ? $value->toIso8601String() : $value;
        }

        return $values;
    }
}
