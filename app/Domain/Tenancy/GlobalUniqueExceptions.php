<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final class GlobalUniqueExceptions
{
    /**
     * @var array<string, string>
     */
    private const INDEXES = [
        'catalogs.slug' => 'Es la URL pública /c/{slug}, compartible por WhatsApp y global: '
            .'dos negocios no pueden reclamar el mismo slug.',

        'layaways.code' => 'Es la prueba de acceso al portal del cliente; de él se resuelve la '
            .'organización sin tenant previo, y esa unicidad global es lo que lo hace servir de '
            .'credencial.',
    ];

    /**
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
            .'plataforma —el feed multi-negocio ordena por su created_at—, y esa '
            .'consulta cruza organizaciones por diseño: la columna no filtraría nada y quedaría como un '
            .'segundo censo derivable de catalog_id. Mismo caso que catalog_subscriptions, y se resuelve '
            .'igual: declarado. Lo que impide mezclar el producto de un negocio con el catálogo de otro '
            .'es la validación de SyncCatalogProductsAction.',
    ];

    /**
     * @return array<string, string>
     */
    public static function indexes(): array
    {
        return self::INDEXES;
    }

    /**
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return self::TABLES;
    }

    public static function allowsGlobalUnique(string $table, string $column): bool
    {
        return array_key_exists($table.'.'.$column, self::INDEXES);
    }

    public static function allowsMissingTenantColumn(string $table): bool
    {
        return array_key_exists($table, self::TABLES);
    }

    public static function reasonFor(string $key): ?string
    {
        return self::INDEXES[$key] ?? self::TABLES[$key] ?? null;
    }
}
