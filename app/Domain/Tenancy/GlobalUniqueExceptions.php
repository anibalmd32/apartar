<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Lista blanca del aislamiento: lo poco que legítimamente no lleva
 * `organization_id`.
 *
 * La regla de `REQ-ORG-002` RB-3 es que **todo índice único de negocio incluye
 * `organization_id`**, y la de [02 §5] que **toda tabla del tenant lleva la
 * columna**. Esta clase declara las únicas excepciones, con su motivo escrito:
 * dentro de seis meses parecerán descuidos, y sin el motivo alguien las
 * "arreglará".
 *
 * Está en código y no en un comentario a propósito. `tenancy:audit` (`T-007`,
 * `REQ-QAX-008` RB-4) deriva de aquí su comprobación estática y **falla si
 * aparece una cuarta excepción**; una lista blanca que hubiera que mantener en
 * dos sitios se desincronizaría, y lo haría justo en la dirección peligrosa:
 * el código con una excepción de más y el documento sin ella.
 */
final class GlobalUniqueExceptions
{
    /**
     * Índices únicos que no incluyen `organization_id`, por `tabla.columna`.
     *
     * Los tres comparten la misma forma: son claves que hay que poder resolver
     * **antes** de saber en qué organización estamos.
     *
     * @var array<string, string>
     */
    private const INDEXES = [
        'users.email' => 'El login del backoffice no pide organización: se deriva del usuario, '
            .'así que el correo tiene que ser único en toda la plataforma (REQ-BOF-001 RB-2).',

        'catalogs.slug' => 'Es la URL pública /c/{slug}, compartible por WhatsApp y global: '
            .'dos negocios no pueden reclamar el mismo slug (Q4).',

        'layaways.code' => 'Es la prueba de acceso al portal del cliente; de él se resuelve la '
            .'organización sin tenant previo, y esa unicidad global es lo que lo hace servir de '
            .'credencial (REQ-IDN-008 RB-3).',
    ];

    /**
     * Tablas exentas de llevar `organization_id`.
     *
     * Son de dos clases, y conviene no confundirlas:
     *
     *  - **Puentes de plataforma** (`catalog_subscriptions`,
     *    `catalog_digest_dispatches`): relacionan una `Account` global con un
     *    catálogo concreto y cruzan la frontera del tenant a propósito.
     *  - **Pivotes entre dos tablas ya aisladas** (`product_tag`,
     *    `catalog_product`): sus filas solo se alcanzan desde padres que ya
     *    llevan el global scope, así que la columna sería un segundo censo de
     *    a quién pertenece la fila —derivable de cualquiera de sus extremos y
     *    capaz de discrepar de ellos—.
     *
     * Lo que **no** hay es una tercera clase: una tabla con modelo propio y
     * consultas propias lleva su `organization_id`, sin excepción.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'catalog_subscriptions' => 'Puente entre una Account global y un catálogo: pertenece a la capa '
            .'de plataforma y cruza la frontera del tenant a propósito ([05 §4] bloque 6).',

        'catalog_digest_dispatches' => 'Registro de envíos del digest por cuenta y catálogo: misma capa '
            .'de plataforma que catalog_subscriptions ([05 §4] bloque 6).',

        'product_tag' => 'Pivote puro entre products y tags, las dos ya aisladas: a una fila solo se '
            .'llega desde un producto o desde una etiqueta, los dos con el global scope puesto, así que '
            .'una fila solo existe si alguien tenía acceso a sus dos extremos ([05 §4] bloque 5).',

        'catalog_product' => 'Pivote entre catalogs y products. Se lee además desde la capa de '
            .'plataforma —el feed multi-negocio ordena por su created_at (REQ-CAT-023 RB-3)—, y esa '
            .'consulta cruza organizaciones por diseño: la columna no filtraría nada y quedaría como un '
            .'segundo censo derivable de catalog_id. Mismo caso que catalog_subscriptions, y se resuelve '
            .'igual: declarado. Lo que impide mezclar el producto de un negocio con el catálogo de otro '
            .'es la validación de SyncCatalogProductsAction (REQ-CAT-023 RB-1).',
    ];

    /**
     * Índices únicos permitidos sin `organization_id`, con su motivo.
     *
     * @return array<string, string>
     */
    public static function indexes(): array
    {
        return self::INDEXES;
    }

    /**
     * Tablas del tenant permitidas sin la columna `organization_id`, con su
     * motivo.
     *
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return self::TABLES;
    }

    /**
     * Indica si ese índice único puede prescindir de `organization_id`.
     *
     * @param  string  $table  nombre de la tabla
     * @param  string  $column  columna única
     */
    public static function allowsGlobalUnique(string $table, string $column): bool
    {
        return array_key_exists($table.'.'.$column, self::INDEXES);
    }

    /**
     * Indica si esa tabla puede prescindir de la columna `organization_id`.
     *
     * @param  string  $table  nombre de la tabla
     */
    public static function allowsMissingTenantColumn(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    /**
     * Motivo declarado de una excepción, o `null` si no está en la lista.
     *
     * Que el motivo sea consultable permite a `tenancy:audit` imprimirlo en su
     * informe, en lugar de limitarse a decir que algo está permitido.
     *
     * @param  string  $key  `tabla.columna` para un índice, o `tabla` para una tabla exenta
     */
    public static function reasonFor(string $key): ?string
    {
        return self::INDEXES[$key] ?? self::TABLES[$key] ?? null;
    }
}
