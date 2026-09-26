<?php

declare(strict_types=1);

namespace App\Actions\Layaway;

use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Catalog\CatalogUnavailableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Domain\Catalog\InsufficientStockException;
use App\Domain\Catalog\ProductLock;
use App\Domain\Catalog\ProductNotAvailableException;
use App\Domain\Layaway\LayawayStatus;
use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\NotAcceptingLayawaysException;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\CreationSuspendedException;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanLimitReachedException;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantAlreadySetException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Product;
use App\Support\LayawayCode;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Crea un apartado desde el formulario del catálogo (`REQ-LAY-001`,
 * `REQ-LAY-002`).
 *
 * Es donde el dinero, el inventario y la identidad se tocan por primera vez, y
 * todas las piezas ya existían probadas por separado: la ficha (`T-043`), la
 * reserva y su bloqueo (`T-044`), las condiciones congeladas (`T-041`), la
 * máquina de estados (`T-042`) y el cupo del plan. Esta acción **las ordena**,
 * y el orden es la mitad de su corrección.
 *
 * ## La secuencia de [05 §5.1]
 *
 *  0. **Idempotencia, camino rápido** (RB-12): si ya existe un apartado con
 *     esta clave, se devuelve sin abrir transacción.
 *  1. **El catálogo admite apartados** (RB-6.1, SP-3), y
 *  2. **el producto es de ese catálogo y está publicado** (RB-6.2, SP-4).
 *     Las dos son lecturas y van **fuera** de la transacción, a propósito: ver
 *     «La foto» abajo.
 *  3. En la transacción, **el producto se bloquea primero** (`REQ-LAY-003`
 *     RB-8) con `ProductLock`, antes que cualquier otra fila del tenant.
 *  4. **Idempotencia otra vez**, ya con el producto bloqueado.
 *  5. **Hay stock** (RB-6.3), sobre la fila bloqueada.
 *  6. **Hay cupo en el plan** (RB-6.4), **después** del stock: un `422` por
 *     el límite cuando además no quedaba stock le contaría al negocio algo que
 *     no es. El mensaje al cliente es neutro (SP-2).
 *  7. **La organización está activa** (RB-6.5; SP-5 la resuelve como SP-3).
 *  8. **La ficha**, con `ResolveCustomerAction` y su consentimiento.
 *  9. **Las condiciones se congelan** en `LayawayTerms` y **se crea el
 *     apartado** en `pending_down_payment`, con su código (RB-7, RB-9).
 * 10. **Se reserva el stock** (`ReserveStockAction`, que vuelve a pedir el
 *     bloqueo) y **sube el cupo** del plan, en la misma transacción.
 *
 * Si algo falla en cualquier punto, **no queda nada**: ni ficha, ni apartado,
 * ni reserva, ni cupo (SP-1, SP-12).
 *
 * ## Por qué el producto va primero
 *
 * `ResolveCustomerAction` toma un `FOR UPDATE` sobre la ficha al reintentar
 * tras una colisión, y dos transacciones que tomen producto y ficha en orden
 * distinto se abrazan. Ese fallo **no sale en la suite** —un *deadlock* solo
 * existe entre transacciones confirmadas—: sale en producción como un `1213`
 * sin causa aparente. Con el producto primero, dos apartados del mismo
 * producto se serializan **antes** de llegar a la ficha, y eso resuelve
 * también el caso de tres peticiones simultáneas del mismo documento nuevo
 * que `T-043` dejó anotado. `CreateLayawayActionTest` lo afirma sobre el
 * registro de consultas con `AssertsStockLockOrder`.
 *
 * ## La foto, y por qué las lecturas previas van fuera
 *
 * En `REPEATABLE READ`, la primera lectura normal de una transacción fija la
 * foto que verán todas las demás. Si el catálogo se leyera **dentro** antes de
 * bloquear el producto, la foto sería anterior a que confirmase otro envío con
 * la misma clave que estaba esperando el mismo bloqueo, y la comprobación del
 * paso 4 no lo vería. Un `FOR UPDATE` no fija la foto; la primera lectura
 * normal después de él, sí, y ya ve lo confirmado.
 *
 * ## Dos envíos con la misma clave
 *
 *  - **Uno detrás de otro**: el segundo sale en el paso 0 con el mismo
 *    apartado. Sin transacción, sin bloqueos, sin tocar la ficha.
 *  - **A la vez, del mismo producto**: el segundo espera el bloqueo del
 *    producto, entra cuando el primero confirmó, y lo encuentra en el paso 4
 *    —**también si era la última unidad**: devuelve el apartado, no un `422`
 *    de stock—.
 *  - **A la vez, con otro producto** (un formulario manipulado): no se
 *    serializan por el producto, y es `UNIQUE(organization_id,
 *    idempotency_key)` quien choca. La transacción del segundo se deshace
 *    entera y se devuelve el del primero.
 *
 * En los tres casos el apartado que vuelve tiene `wasRecentlyCreated` a
 * `false`, que es lo que `T-045b` usa para no mandar el correo dos veces.
 *
 * ## Lo que no hace
 *
 * No genera cronograma (RB-8, `T-046`), no emite `layaway_created` (RB-11,
 * `T-084`) ni encola el correo (RB-10, `T-045b`), y no vincula cuenta ni
 * suscribe al catálogo (RB-4, H6). Los tres últimos tienen su caso en rojo
 * diferido en `CreateLayawayActionTest`.
 */
final class CreateLayawayAction
{
    /** El nombre que Laravel da al índice de la clave (migración de `T-045`). */
    private const IDEMPOTENCY_INDEX = 'layaways_organization_id_idempotency_key_unique';

    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitEnforcer $limits,
        private readonly Settings $settings,
        private readonly ResolveCustomerAction $customers,
        private readonly ReserveStockAction $stock,
    ) {}

    /**
     * El apartado de esta clave: el que se crea ahora o el que ya existía.
     *
     * @throws CatalogUnavailableException si el catálogo ya no admite apartados, o la organización está suspendida (SP-3, SP-5)
     * @throws ProductNotAvailableException si el producto no es de ese catálogo o ya no está publicado (SP-4)
     * @throws InsufficientStockException si no quedan las unidades pedidas (SP-1)
     * @throws NotAcceptingLayawaysException si el plan del negocio no admite otro apartado (SP-2)
     * @throws TenantAlreadySetException si hay fijada otra organización que la del catálogo
     */
    public function handle(CreateLayawayData $data): Layaway
    {
        $organization = $this->organizationOf($data->catalog);

        return $this->context->runFor($organization, fn (): Layaway => $this->create($data, $organization));
    }

    private function create(CreateLayawayData $data, Organization $organization): Layaway
    {
        // 0 · RB-12, el caso de todos los días: el cliente pulsó dos veces.
        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $existing;
        }

        // 1 y 2 · lecturas, fuera de la transacción (ver «La foto»).
        $this->assertCatalogIsOpen($data->catalog);
        $this->assertProductIsInCatalog($data->catalog, $data->product);

        try {
            return DB::transaction(fn (): Layaway => $this->transact($data, $organization));
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), self::IDEMPOTENCY_INDEX)) {
                throw $exception;
            }

            // Dos envíos simultáneos con la misma clave y distinto producto:
            // lo nuestro ya se deshizo entero, y el que ganó está confirmado.
            // Con `FOR UPDATE` para leer lo confirmado aunque quien llama
            // tenga abierta una transacción con su foto.
            return Layaway::query()->where('idempotency_key', $data->idempotencyKey)->lockForUpdate()->first()
                ?? throw $exception;
        }
    }

    private function transact(CreateLayawayData $data, Organization $organization): Layaway
    {
        // 3 · `REQ-LAY-003` RB-8: el producto antes que cualquier otra fila.
        $product = ProductLock::acquire((int) $data->product->getKey());

        // 4 · RB-12 bajo el bloqueo: otro envío con esta clave y este producto
        // esperaba el mismo bloqueo, y ya confirmó.
        $existing = $this->findByKey($data->idempotencyKey);

        if ($existing instanceof Layaway) {
            return $existing;
        }

        // El estado, sobre la fila bloqueada: pudo archivarse mientras tanto.
        ProductNotAvailableException::guard($product);

        // 5 · RB-6.3, bajo el bloqueo. `ReserveStockAction` lo vuelve a
        // comprobar al descontar; aquí va para que el orden de los rechazos
        // sea el de RB-6 y el cupo no hable antes que el stock.
        if ($product->stock < $data->quantity) {
            throw InsufficientStockException::for($product, $data->quantity);
        }

        // 6 · RB-6.4.
        $this->assertAcceptingLayaways();

        // 7 · RB-6.5.
        if ($organization->status !== OrganizationStatus::Active) {
            throw CatalogUnavailableException::for($data->catalog);
        }

        // 8 · la ficha, con el tenant ya fijado y el producto ya bloqueado.
        $customer = $this->customers->handle($data->contact, $data->consent);

        // 9 · `REQ-LAY-002`: se congela desde el producto **bloqueado**, que
        // es la versión que de verdad se vende. A partir de aquí el apartado
        // no vuelve a leer `products` para nada financiero.
        $terms = new LayawayTerms(
            unitPrice: $product->price_cents,
            downPayment: $product->down_payment_cents,
            quantity: $data->quantity,
            installmentsCount: $product->installments_count,
            frequencyDays: $product->frequency_days,
        );

        $layaway = LayawayCode::claim(fn (string $code): Layaway => $this->insert($data, $product, $customer, $terms, $code));

        // 10 · la unidad y el cupo, en la misma transacción.
        $this->stock->handle($product, $data->quantity);
        $this->limits->adjust(UsageMetric::ActiveLayaways, 1);

        // RB-4 · con sesión de cuenta, aquí van `LinkAccountAction`
        // (`T-077`) y `SubscribeToCatalogAction` (`T-075`). `Account` no existe
        // todavía; `CreateLayawayActionTest` se pone rojo el día que exista.

        return $layaway;
    }

    private function insert(CreateLayawayData $data, Product $product, Customer $customer, LayawayTerms $terms, string $code): Layaway
    {
        $total = $terms->total();

        $layaway = new Layaway;

        $layaway->forceFill([
            'code' => $code,
            'idempotency_key' => $data->idempotencyKey,
            'customer_id' => $customer->getKey(),
            'product_id' => $product->getKey(),
            // RB-1 · de dónde salió; informativo (`REQ-LAY-002` RB-7).
            'catalog_id' => $data->catalog->getKey(),

            // `REQ-LAY-002` · el snapshot. Precio e inicial **por unidad**
            // (RB-2); cuotas y frecuencia **sin multiplicar** (RB-3).
            'quantity' => $terms->quantity,
            'unit_price_cents' => $terms->unitPrice,
            'down_payment_cents' => $terms->downPayment,
            'installments_count' => $terms->installmentsCount,
            'frequency_days' => $terms->frequencyDays,
            'product_name_snapshot' => $product->name,
            'product_sku_snapshot' => $product->sku,
            'total_cents' => $total,

            // `REQ-PAY-008` · el balance nace igual al total y sin pagos; a
            // partir de aquí solo lo escribe `LayawayBalanceRecalculator`.
            'paid_cents' => 0,
            'remaining_cents' => $total,

            // RB-7 · nace esperando la inicial, con su ventana.
            'status' => LayawayStatus::PendingDownPayment,
            'expires_at' => CarbonImmutable::now()->addHours($this->downPaymentWindowHours()),
        ])->save();

        return $layaway;
    }

    /**
     * RB-1 · la organización sale del catálogo. Si ya hay otra fijada —un
     * middleware que resolvió mal, un job que no limpió—, se lanza en vez de
     * crear el apartado en la que no es.
     *
     * Una organización dada de baja (borrada lógicamente) no aparece, y su
     * catálogo se trata como uno que ya no está disponible (SP-5): para el
     * cliente es lo mismo que una suspendida.
     */
    private function organizationOf(Catalog $catalog): Organization
    {
        $organization = Organization::query()->find($catalog->organization_id)
            ?? throw CatalogUnavailableException::for($catalog);

        if ($this->context->hasOrganization() && ! $this->context->organization()->is($organization)) {
            throw TenantAlreadySetException::cannotSwitch($this->context->organizationId(), (int) $organization->getKey());
        }

        return $organization;
    }

    /**
     * RB-6.1 · publicado y sin caducar, en la vitrina o por enlace (SP-3).
     */
    private function assertCatalogIsOpen(Catalog $catalog): void
    {
        $open = match ($catalog->visibility()) {
            CatalogVisibility::Listed, CatalogVisibility::Unlisted => true,
            CatalogVisibility::Draft, CatalogVisibility::Expired => false,
        };

        if (! $open) {
            throw CatalogUnavailableException::for($catalog);
        }
    }

    /**
     * RB-6.2 · el producto está **en este catálogo** y publicado ya
     * (`scopePublished()`, que excluye también el programado). El de otro
     * catálogo del mismo negocio no está, y el de otra organización tampoco:
     * `products` va por el global scope (SP-4).
     */
    private function assertProductIsInCatalog(Catalog $catalog, Product $product): void
    {
        $inCatalog = $catalog->products()->published()->whereKey($product->getKey())->exists();

        if (! $inCatalog) {
            throw ProductNotAvailableException::for($product);
        }
    }

    /**
     * RB-6.4 · el cupo de `active_layaways`, con el mensaje neutro de SP-2.
     * El motivo real viaja dentro, para el aviso del negocio.
     */
    private function assertAcceptingLayaways(): void
    {
        try {
            $this->limits->assertCanCreate(UsageMetric::ActiveLayaways);
        } catch (PlanLimitReachedException|CreationSuspendedException $reason) {
            throw NotAcceptingLayawaysException::because($reason);
        }
    }

    private function findByKey(string $key): ?Layaway
    {
        return Layaway::query()->where('idempotency_key', $key)->first();
    }

    private function downPaymentWindowHours(): int
    {
        $hours = $this->settings->get(SettingKey::DownPaymentWindowHours);

        return is_numeric($hours) ? (int) $hours : (int) SettingKey::DownPaymentWindowHours->defaultValue();
    }
}
