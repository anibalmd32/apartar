<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Lectura del esquema real de MySQL para los tests de migraciones.
 *
 * Las comprobaciones de esquema van contra la base de datos y no contra el
 * texto de las migraciones: lo que importa no es lo que una migración dice que
 * crea, sino lo que queda creado después de que corran todas.
 */
final class SchemaInspector
{
    /**
     * Columnas de una tabla, indexadas por nombre.
     *
     * @return array<string, array{type_name: string, type: string, nullable: bool, default: string|null}>
     */
    public static function columns(string $table): array
    {
        $columns = [];

        foreach (Schema::getColumns($table) as $column) {
            $columns[$column['name']] = [
                'type_name' => $column['type_name'],
                'type' => $column['type'],
                'nullable' => $column['nullable'],
                'default' => $column['default'] === null ? null : (string) $column['default'],
            ];
        }

        return $columns;
    }

    /**
     * Índices de una tabla.
     *
     * @return list<array{columns: list<string>, unique: bool, primary: bool}>
     */
    public static function indexes(string $table): array
    {
        return array_map(
            fn (array $index): array => [
                'columns' => $index['columns'],
                'unique' => $index['unique'],
                'primary' => $index['primary'],
            ],
            Schema::getIndexes($table),
        );
    }

    /**
     * Indica si existe un índice sobre exactamente esas columnas y en ese
     * orden. El orden importa: `(source, published_at)` y
     * `(published_at, source)` sirven a consultas distintas.
     */
    public static function hasIndexOn(string $table, string ...$columns): bool
    {
        foreach (self::indexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si ese índice existe y además es único.
     */
    public static function hasUniqueOn(string $table, string ...$columns): bool
    {
        foreach (self::indexes($table) as $index) {
            if ($index['columns'] === $columns && $index['unique']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tablas de las que somos dueños, excluidas las del framework y las que
     * publican los paquetes.
     *
     * @return list<string>
     */
    public static function projectTables(): array
    {
        $foreign = [
            'migrations', 'media', 'activity_log', 'push_subscriptions',
            'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
            'sessions', 'password_reset_tokens',
        ];

        return array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            fn (string $table): bool => ! in_array($table, $foreign, true),
        ));
    }
}
