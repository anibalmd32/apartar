<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\Schema;

final class SchemaInspector
{
    /**
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

    public static function hasIndexOn(string $table, string ...$columns): bool
    {
        foreach (self::indexes($table) as $index) {
            if ($index['columns'] === $columns) {
                return true;
            }
        }

        return false;
    }

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
     * @return array{foreign_table: string, on_delete: string}|null
     */
    public static function foreignKey(string $table, string $column): ?array
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if ($key['columns'] === [$column]) {
                return [
                    'foreign_table' => $key['foreign_table'],
                    'on_delete' => strtolower((string) $key['on_delete']),
                ];
            }
        }

        return null;
    }

    /**
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
