<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Throwable;

/**
 * Los ajustes de la organización activa, con caché **por organización**
 * (`REQ-ORG-006` RB-3).
 *
 * ## Por qué esto es aislamiento y no rendimiento
 *
 * Suena a detalle de velocidad y no lo es. Estos valores gobiernan períodos de
 * gracia, ventanas de abono inicial, frecuencias de cuota y tolerancias de
 * liquidación: si la caché se comparte por error, el sistema **no falla**.
 * Calcula fechas de vencimiento con los plazos de otro negocio, las comunica
 * al cliente, y el error se descubre cuando ese cliente reclama —semanas
 * después y sin nada en ningún log—. De ahí que la clave sea
 * `settings:{organization_id}` y que haya un test que intenta la fuga a
 * propósito en vez de limitarse a comprobar que dos lecturas difieren.
 *
 * El memo en memoria tiene el mismo cuidado: va indexado por organización, no
 * por instancia. Es donde una fuga sería más fácil de escribir y más difícil
 * de ver, porque no deja rastro fuera del proceso.
 *
 * ## Redis caído se lee de BD
 *
 * SP-7, y no es una optimización opcional: es la diferencia entre una caída de
 * Redis que ralentiza el backoffice y una que para el negocio. Toda operación
 * de caché va envuelta; si el almacén lanza, se sigue adelante contra la base
 * de datos y quien llamó no se entera. La caché es un atajo, nunca un
 * requisito para responder.
 *
 * ## Las filas mandan, los defaults rellenan
 *
 * El registro siembra las diez filas (`REQ-ORG-001` RB-1), así que faltar no
 * debería faltar ninguna; si falta, se lee su `defaultValue()` en vez de
 * devolver `null`. Y una fila cuya clave este código ya no conoce **se
 * ignora** (RB-2): es el caso de `storefront_enabled` en una base sembrada
 * antes de `T-014`, y el que permite desplegar sin coordinar la migración de
 * datos al segundo.
 */
final class Settings
{
    /**
     * Prefijo de la clave de caché, tal como lo fija RB-3.
     */
    public const CACHE_PREFIX = 'settings:';

    /**
     * Vida de la entrada. Larga a propósito: la invalidación es explícita en
     * cada escritura, y el TTL solo está para que una entrada huérfana —de una
     * organización cerrada, por ejemplo— no viva para siempre.
     */
    public const TTL_SECONDS = 86_400;

    /**
     * Ajustes ya resueltos en esta petición, **por organización**.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Valor de un ajuste de la organización activa.
     */
    public function get(SettingKey $key): mixed
    {
        return $this->all()[$key->value] ?? $key->defaultValue();
    }

    /**
     * Los días de gracia de la organización activa (`REQ-LAY-007` RB-1): la
     * detección de mora, la cobranza y el directorio cuentan con el mismo.
     */
    public function graceDays(): int
    {
        $days = $this->get(SettingKey::GracePeriodDays);

        return is_numeric($days) ? (int) $days : (int) SettingKey::GracePeriodDays->defaultValue();
    }

    /**
     * Las diez claves de la organización activa, resueltas.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->forOrganization($this->context->organization());
    }

    /**
     * Las diez claves de esa organización, resueltas.
     *
     * Recibe la organización en lugar de tomarla del contexto para que los
     * comandos que recorren la plataforma —y los tests de fuga— puedan pedir
     * las de una concreta sin fijar el tenant. Quien la pasa ya la tiene; lo
     * que no se puede es adivinarla.
     *
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $organization): array
    {
        $organizationId = (int) $organization->getKey();

        if (array_key_exists($organizationId, $this->memo)) {
            return $this->memo[$organizationId];
        }

        $cached = $this->remember($organizationId, fn (): array => $this->readFromDatabase($organization));

        return $this->memo[$organizationId] = $cached;
    }

    /**
     * Olvida lo cacheado de esa organización (RB-3: se invalida en cada
     * escritura).
     *
     * Sin organización, la del contexto. Se borran **las dos copias** —la de
     * la caché y la del memo—, porque la escritura y la lectura siguiente
     * suelen ocurrir en la misma petición: invalidar solo Redis dejaría a
     * quien acaba de guardar viendo lo de antes, que es el bug que hace dudar
     * de si el formulario funciona.
     */
    public function forget(?Organization $organization = null): void
    {
        $organization ??= $this->context->organization();
        $organizationId = (int) $organization->getKey();

        unset($this->memo[$organizationId]);

        $this->withoutFailing(fn () => $this->cache->forget(self::cacheKeyFor($organizationId)));
    }

    /**
     * Clave de caché de una organización (RB-3).
     */
    public static function cacheKeyFor(int $organizationId): string
    {
        return self::CACHE_PREFIX.$organizationId;
    }

    /**
     * Lee de caché y, si no está, resuelve y guarda.
     *
     * No se usa `Cache::remember()` porque necesita **fallar hacia la base de
     * datos** en las dos mitades por separado (SP-7): si la lectura lanza hay
     * que resolver igual, y si la escritura lanza hay que devolver lo
     * resuelto en vez de propagar el fallo del almacén.
     *
     * @param  \Closure(): array<string, mixed>  $resolve
     * @return array<string, mixed>
     */
    private function remember(int $organizationId, \Closure $resolve): array
    {
        $cacheKey = self::cacheKeyFor($organizationId);

        $cached = $this->withoutFailing(fn (): mixed => $this->cache->get($cacheKey));

        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $resolve();

        $this->withoutFailing(fn () => $this->cache->put($cacheKey, $resolved, self::TTL_SECONDS));

        return $resolved;
    }

    /**
     * Las filas de esa organización, con los defaults rellenando los huecos.
     *
     * La consulta va por el global scope dentro de `runFor()` y no con un
     * `where` a mano: `organization_settings` es una tabla del tenant y
     * escribir su filtro aquí sería la excepción por la que se acaban colando
     * las demás (`REQ-ORG-002` RB-1 y RB-2).
     *
     * @return array<string, mixed>
     */
    private function readFromDatabase(Organization $organization): array
    {
        $rows = $this->context->runFor(
            $organization,
            fn (): array => OrganizationSetting::query()->get()->all(),
        );

        $values = SettingKey::defaults();

        foreach ($rows as $row) {
            // Se lee el valor crudo de la columna y no `$row->key`: el cast al
            // enum lanzaría con una fila de una clave retirada, y RB-2 quiere
            // que esa fila se ignore, no que tumbe la lectura entera.
            $key = SettingKey::tryFromKey((string) $row->getRawOriginal('key'));

            if ($key === null) {
                continue;
            }

            $values[$key->value] = $row->value;
        }

        return $values;
    }

    /**
     * Ejecuta una operación de caché tolerando que el almacén esté caído
     * (SP-7).
     *
     * Se capturan `Throwable` y no solo las excepciones de conexión a
     * propósito: el contrato con quien llama es «la caché nunca es el motivo
     * de que esto falle», y acotar la captura a las excepciones que hoy lanza
     * phpredis convertiría el cambio de driver de mañana en una caída.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $operation
     * @return TReturn|null
     */
    private function withoutFailing(\Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable) {
            return null;
        }
    }
}
