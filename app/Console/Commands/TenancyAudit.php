<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use App\Support\SourceFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class TenancyAudit extends Command
{
    /** @var string */
    protected $signature = 'tenancy:audit
        {--root= : Raíz del árbol que se audita; por defecto, la del proyecto}';

    /** @var string */
    protected $description = 'Audita el aislamiento multi-tenant sobre el código';

    /**
     * @var array<string, string>
     */
    private const ALLOWED_SCOPE_EXCLUSION_PATHS = [
        'app/Queries/Platform' => 'El backoffice de plataforma ve a todas las organizaciones.',
        'app/Actions/Platform' => 'Las acciones de plataforma operan sin tenant.',
        'app/Auth/TenantAwareUserProvider.php' => 'El login ocurre antes de conocer la organización.',
        'app/Auth/TeamMemberships.php' => 'Las filas de una misma persona en sus negocios, por su correo: el login y el cambio de negocio.',
        'app/Console/Commands' => 'Los comandos de mantenimiento recorren la plataforma entera.',
    ];

    private const SCOPE_DECLARATION_FILE = 'app/Domain/Tenancy/BelongsToOrganization.php';

    /**
     * @var array<string, string>
     */
    private const NON_TENANT_TABLES = [
        'platform_users' => 'Operadores de la plataforma, tabla separada por construcción.',
        'plans' => 'Catálogo de planes, común a toda la plataforma.',
        'organizations' => 'Es el tenant; no está sujeta a él.',
        'exchange_rates' => 'Tasa única para toda la plataforma, ingestada una sola vez.',
        'accounts' => 'Cuenta global del cliente final, anterior a cualquier organización.',
        'account_identities' => 'Identidades de acceso de una Account global: misma capa de plataforma que accounts ([05 §4] bloque 4).',
        'platform_settings' => 'Ajustes de la plataforma, no de un negocio.',
        'banks' => 'Catálogo de bancos venezolanos, común a toda la plataforma.',
        'mobile_prefixes' => 'Catálogo de prefijos de celular y su operadora, común a toda la plataforma.',
        'document_prefixes' => 'Catálogo de prefijos de documento (V, E, J), común a toda la plataforma.',
        'platform_activity_log' => 'Auditoría de los operadores; la organización afectada va en target_organization_id, sin scope.',
        'sent_mails' => 'Registro de todo correo que sale de la plataforma; la organización, si se conoce, va en target_organization_id, sin scope.',
        'sent_mail_events' => 'Eventos de Mailtrap de los correos enviados: misma capa de plataforma que sent_mails.',
        'mail_threads' => 'Conversaciones del buzón de la plataforma (info@), sin negocio.',
        'inbound_mails' => 'Correos recibidos en el dominio de la plataforma, sin negocio.',
        'inbound_mail_attachments' => 'Adjuntos de los correos recibidos: misma capa de plataforma que inbound_mails.',

        'password_reset_tokens' => 'Tabla del framework, indexada por correo.',
        'sessions' => 'Tabla del framework.',
        'cache' => 'Tabla del framework.',
        'cache_locks' => 'Tabla del framework.',
        'jobs' => 'Tabla del framework.',
        'job_batches' => 'Tabla del framework.',
        'failed_jobs' => 'Tabla del framework.',
        'media' => 'Tabla de spatie/laravel-medialibrary: se ata a su modelo por relación polimórfica.',
        'push_subscriptions' => 'Tabla de laravel-notification-channels/webpush: se ata a su notificable.',
        'pulse_values' => 'Tabla de laravel/pulse: métricas técnicas de toda la plataforma.',
        'pulse_entries' => 'Tabla de laravel/pulse: métricas técnicas de toda la plataforma.',
        'pulse_aggregates' => 'Tabla de laravel/pulse: métricas técnicas de toda la plataforma.',
        'telescope_entries' => 'Tabla de laravel/telescope: trazas técnicas de toda la plataforma.',
        'telescope_entries_tags' => 'Tabla de laravel/telescope: etiquetas de sus trazas.',
        'telescope_monitoring' => 'Tabla de laravel/telescope: etiquetas vigiladas.',

    ];

    private const SCHEMA_CALL = '/Schema::(?:[A-Za-z]+\(.*?\)\s*->\s*)?(create|table)';

    /**
     * @var list<string>
     */
    private array $unreadableMigrations = [];

    public function handle(): int
    {
        $root = $this->auditedRoot();

        $tables = $this->tablesFromMigrations($root);
        $models = $this->modelsFrom($root, $tables);

        $findings = [
            'modelos de una tabla con organization_id sin BelongsToOrganization' => $this->modelsWithoutTrait($models),
            'índices únicos del tenant sin organization_id' => $this->globalUniqueIndexes($tables),
            'exclusión del scope fuera de las rutas permitidas' => $this->scopeExclusions($root),
            'tablas del tenant sin organization_id o sin su índice' => $this->tenantTablesWithoutColumn($tables),
            'factories de modelos del tenant sin CreatesWithinTenant' => $this->factoriesWithoutTrait($root, $models),
        ];

        return $this->report($root, $findings);
    }

    /**
     * @param  array<string, list<string>>  $findings
     */
    private function report(string $root, array $findings): int
    {
        $this->newLine();
        $this->line('  Auditoría de aislamiento multi-tenant');
        $this->line('  Árbol auditado: '.$root);
        $this->newLine();

        $total = 0;

        foreach ($findings as $check => $violations) {
            $total += count($violations);

            $this->line(sprintf('  %s %s', $violations === [] ? '✔' : '✘', $check));

            foreach ($violations as $violation) {
                $this->line('      · '.$violation);
            }
        }

        if ($this->unreadableMigrations !== []) {
            $this->newLine();
            $this->line('  Migraciones con nombre de tabla no literal, fuera del alcance estático:');

            foreach ($this->unreadableMigrations as $migration) {
                $this->line('      · '.$migration);
            }
        }

        $this->newLine();

        if ($total > 0) {
            $this->error(sprintf(
                '  %d violación(es) del aislamiento. No se admiten excepciones ni baseline.',
                $total,
            ));

            return self::FAILURE;
        }

        $this->info('  Sin violaciones del aislamiento.');

        return self::SUCCESS;
    }

    private function auditedRoot(): string
    {
        $root = $this->option('root');

        return rtrim(is_string($root) && $root !== '' ? $root : base_path(), '/\\');
    }

    /**
     * @param  list<array{class: string, file: string, table: string, tenant: bool, trait: bool}>  $models
     * @return list<string>
     */
    private function modelsWithoutTrait(array $models): array
    {
        $violations = [];

        foreach ($models as $model) {
            if ($model['tenant'] && ! $model['trait']) {
                $violations[] = sprintf(
                    '%s (tabla %s) no usa BelongsToOrganization · %s',
                    $model['class'],
                    $model['table'],
                    $model['file'],
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, array{file: string, created: bool, column: bool, indexed: bool, uniques: list<array{columns: list<string>, file: string}>}>  $tables
     * @return list<string>
     */
    private function globalUniqueIndexes(array $tables): array
    {
        $violations = [];

        foreach ($tables as $table => $definition) {
            if (! $definition['column']) {
                continue;
            }

            foreach ($definition['uniques'] as $unique) {
                if (in_array(TenantScope::COLUMN, $unique['columns'], true)) {
                    continue;
                }

                $allowed = array_filter(
                    $unique['columns'],
                    fn (string $column): bool => GlobalUniqueExceptions::allowsGlobalUnique($table, $column),
                );

                if ($allowed !== []) {
                    continue;
                }

                $violations[] = sprintf(
                    'UNIQUE(%s) en %s no incluye %s y no está en GlobalUniqueExceptions · %s',
                    implode(', ', $unique['columns']),
                    $table,
                    TenantScope::COLUMN,
                    $unique['file'],
                );
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function scopeExclusions(string $root): array
    {
        $violations = [];

        foreach (['app', 'database', 'routes'] as $directory) {
            foreach ($this->phpFilesIn($root.'/'.$directory) as $file) {
                $relative = $this->relative($root, $file);

                if ($relative === self::SCOPE_DECLARATION_FILE || $this->isAllowedScopeExclusion($relative)) {
                    continue;
                }

                $code = $this->sourceOf($file, withoutStrings: true);

                foreach ($this->scopeExclusionSpellings($code) as $spelling) {
                    $violations[] = sprintf('%s usa %s · fuera de la lista cerrada de rutas permitidas', $relative, $spelling);
                }
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function scopeExclusionSpellings(string $code): array
    {
        $spellings = [];

        if (preg_match('/(->|::)\s*withoutTenantScope\s*\(/', $code) === 1) {
            $spellings[] = 'withoutTenantScope()';
        }

        if (preg_match('/withoutGlobalScope\s*\(\s*TenantScope\s*::\s*class/', $code) === 1) {
            $spellings[] = 'withoutGlobalScope(TenantScope::class)';
        }

        return $spellings;
    }

    private function isAllowedScopeExclusion(string $relative): bool
    {
        foreach (array_keys(self::ALLOWED_SCOPE_EXCLUSION_PATHS) as $allowed) {
            if ($relative === $allowed || str_starts_with($relative, $allowed.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{file: string, created: bool, column: bool, indexed: bool, uniques: list<array{columns: list<string>, file: string}>}>  $tables
     * @return list<string>
     */
    private function tenantTablesWithoutColumn(array $tables): array
    {
        $violations = [];

        foreach ($tables as $table => $definition) {
            if (! $definition['created'] || $this->isNonTenantTable($table)) {
                continue;
            }

            if (! $definition['column']) {
                $violations[] = sprintf(
                    '%s se crea sin %s y no está declarada fuera del tenant · %s',
                    $table,
                    TenantScope::COLUMN,
                    $definition['file'],
                );

                continue;
            }

            if (! $definition['indexed']) {
                $violations[] = sprintf(
                    '%s lleva %s pero ningún índice empieza por ella · %s',
                    $table,
                    TenantScope::COLUMN,
                    $definition['file'],
                );
            }
        }

        return $violations;
    }

    private function isNonTenantTable(string $table): bool
    {
        return array_key_exists($table, self::NON_TENANT_TABLES)
            || GlobalUniqueExceptions::allowsMissingTenantColumn($table);
    }

    /**
     * @param  list<array{class: string, file: string, table: string, tenant: bool, trait: bool}>  $models
     * @return list<string>
     */
    private function factoriesWithoutTrait(string $root, array $models): array
    {
        $tenantModels = [];

        foreach ($models as $model) {
            if ($model['trait']) {
                $tenantModels[] = $model['class'];
            }
        }

        $violations = [];

        foreach ($this->phpFilesIn($root.'/database/factories') as $file) {
            if (! str_ends_with($file, 'Factory.php')) {
                continue;
            }

            $code = $this->sourceOf($file);
            $class = $this->classNameIn($code);

            if ($class === null) {
                continue;
            }

            $model = preg_match('/\$model\s*=\s*([A-Za-z_][A-Za-z0-9_]*)\s*::\s*class/', $code, $matches) === 1
                ? $matches[1]
                : substr($class, 0, -strlen('Factory'));

            if (! in_array($model, $tenantModels, true)) {
                continue;
            }

            if (! $this->usesTrait($code, 'CreatesWithinTenant')) {
                $violations[] = sprintf(
                    '%s siembra %s, que lleva BelongsToOrganization, sin CreatesWithinTenant · %s',
                    $class,
                    $model,
                    $this->relative($root, $file),
                );
            }
        }

        return $violations;
    }

    /**
     * @return array<string, array{file: string, created: bool, column: bool, indexed: bool, uniques: list<array{columns: list<string>, file: string}>}>
     */
    private function tablesFromMigrations(string $root): array
    {
        $tables = [];

        foreach ($this->phpFilesIn($root.'/database/migrations') as $file) {
            $relative = $this->relative($root, $file);
            $code = $this->upOf($this->sourceOf($file));

            if (preg_match(self::SCHEMA_CALL.'\(\s*[^\'"\s)]/', $code) === 1) {
                $this->unreadableMigrations[] = $relative;
            }

            foreach ($this->schemaBlocks($code) as [$operation, $table, $block]) {
                $tables[$table] ??= [
                    'file' => $relative,
                    'created' => false,
                    'column' => false,
                    'indexed' => false,
                    'uniques' => [],
                ];

                if ($operation === 'create') {
                    $tables[$table]['created'] = true;
                    $tables[$table]['file'] = $relative;
                }

                $analysis = $this->analyseBlock($block);

                $tables[$table]['column'] = $tables[$table]['column'] || $analysis['column'];
                $tables[$table]['indexed'] = $tables[$table]['indexed'] || $analysis['indexed'];

                foreach ($analysis['dropped'] as $columns) {
                    $tables[$table]['uniques'] = array_values(array_filter(
                        $tables[$table]['uniques'],
                        fn (array $unique): bool => $this->sameColumns($unique['columns'], $columns) === false,
                    ));
                }

                foreach ($analysis['uniques'] as $columns) {
                    $tables[$table]['uniques'][] = ['columns' => $columns, 'file' => $relative];
                }
            }
        }

        return $tables;
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function schemaBlocks(string $code): array
    {
        if (preg_match_all(self::SCHEMA_CALL.'\(\s*([\'"])([^\'"]+)\2\s*,/', $code, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return [];
        }

        $blocks = [];

        foreach ($matches[3] as $index => $table) {
            $blocks[] = [
                (string) $matches[1][$index][0],
                (string) $table[0],
                $this->blockAt($code, (int) $table[1]),
            ];
        }

        return $blocks;
    }

    private function blockAt(string $code, int $offset): string
    {
        $start = strpos($code, '{', $offset);

        if ($start === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($code);

        for ($position = $start; $position < $length; $position++) {
            if ($code[$position] === '{') {
                $depth++;
            } elseif ($code[$position] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($code, $start, $position - $start + 1);
                }
            }
        }

        return substr($code, $start);
    }

    /**
     * @return array{column: bool, indexed: bool, uniques: list<list<string>>, dropped: list<list<string>>}
     */
    private function analyseBlock(string $block): array
    {
        $column = false;
        $indexed = false;
        $uniques = [];
        $dropped = [];

        foreach (explode(';', $block) as $statement) {
            $mentionsTenant = in_array(TenantScope::COLUMN, $this->quotedStrings($statement), true);
            $column = $column || $mentionsTenant;

            if ($mentionsTenant && (str_contains($statement, '->constrained(') || str_contains($statement, '->foreign('))) {
                $indexed = true;
            }

            $removed = $this->indexColumns($statement, '->dropUnique(');

            if ($removed !== null) {
                $dropped[] = $removed;

                continue;
            }

            foreach (['->index(', '->unique('] as $call) {
                $columns = $this->indexColumns($statement, $call);

                if ($columns === null) {
                    continue;
                }

                if (($columns[0] ?? null) === TenantScope::COLUMN) {
                    $indexed = true;
                }

                if ($call === '->unique(') {
                    $uniques[] = $columns;
                }
            }
        }

        return ['column' => $column, 'indexed' => $indexed, 'uniques' => $uniques, 'dropped' => $dropped];
    }

    private function upOf(string $code): string
    {
        $position = strpos($code, 'function up(');

        return $position === false ? $code : $this->blockAt($code, $position);
    }

    /**
     * @param  list<string>  $first
     * @param  list<string>  $second
     */
    private function sameColumns(array $first, array $second): bool
    {
        sort($first);
        sort($second);

        return $first === $second;
    }

    /**
     * @return list<string>|null
     */
    private function indexColumns(string $statement, string $call): ?array
    {
        $position = strpos($statement, $call);

        if ($position === false) {
            return null;
        }

        $arguments = substr($statement, $position + strlen($call));

        if (preg_match('/^\s*\[([^\]]*)\]/', $arguments, $matches) === 1) {
            return $this->quotedStrings($matches[1]);
        }

        if (preg_match('/^\s*([\'"])([^\'"]+)\1/', $arguments, $matches) === 1) {
            return [$matches[2]];
        }

        $chained = $this->quotedStrings(substr($statement, 0, $position));

        return $chained === [] ? ['(columna encadenada sin nombre)'] : [$chained[0]];
    }

    /**
     * @param  array<string, array{file: string, created: bool, column: bool, indexed: bool, uniques: list<array{columns: list<string>, file: string}>}>  $tables
     * @return list<array{class: string, file: string, table: string, tenant: bool, trait: bool}>
     */
    private function modelsFrom(string $root, array $tables): array
    {
        $models = [];

        foreach ($this->phpFilesIn($root.'/app/Models') as $file) {
            $code = $this->sourceOf($file);
            $class = $this->classNameIn($code);

            if ($class === null) {
                continue;
            }

            $table = preg_match('/\$table\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $matches) === 1
                ? $matches[1]
                : Str::snake(Str::pluralStudly($class));

            $models[] = [
                'class' => $class,
                'file' => $this->relative($root, $file),
                'table' => $table,
                'tenant' => ($tables[$table]['column'] ?? false) === true,
                'trait' => $this->usesTrait($code, 'BelongsToOrganization'),
            ];
        }

        return $models;
    }

    private function classNameIn(string $code): ?string
    {
        return preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches) === 1
            ? $matches[1]
            : null;
    }

    private function usesTrait(string $code, string $trait): bool
    {
        $position = strpos($code, 'class ');

        if ($position === false) {
            return false;
        }

        return preg_match(
            '/\buse\s+[A-Za-z0-9_\\\\, ]*\b'.preg_quote($trait, '/').'\b/',
            substr($code, $position),
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function quotedStrings(string $fragment): array
    {
        if (preg_match_all('/([\'"])([^\'"]*)\1/', $fragment, $matches) === 0) {
            return [];
        }

        return $matches[2];
    }

    private function sourceOf(string $file, bool $withoutStrings = false): string
    {
        $code = '';

        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token)) {
                $code .= $token;

                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $code .= "\n";

                continue;
            }

            if ($withoutStrings && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $code .= "''";

                continue;
            }

            $code .= $token[1];
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        return SourceFiles::in($directory);
    }

    private function relative(string $root, string $file): string
    {
        $normalised = str_replace('\\', '/', $root).'/';

        return str_starts_with($file, $normalised)
            ? substr($file, strlen($normalised))
            : $file;
    }
}
