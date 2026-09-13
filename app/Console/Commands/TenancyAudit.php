<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\GlobalUniqueExceptions;
use App\Domain\Tenancy\TenantScope;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Auditoría estática del aislamiento multi-tenant (`REQ-QAX-008` RB-4).
 *
 * Existe porque los tests solo cubren lo que alguien se acordó de escribir.
 * La suite de fuga comprueba las rutas que hay; esto comprueba las reglas que
 * ninguna ruta ejercita todavía: el modelo que se creó ayer y aún no tiene
 * endpoint, el índice único de una migración que nadie consulta, la exclusión
 * del scope colada en un controlador.
 *
 * **Es estática de verdad: lee archivos, no la base de datos.** Podría leer el
 * esquema migrado, que sería más fiel a lo que MySQL acaba creando, pero
 * entonces no habría forma de inyectarle una violación sin un `CREATE TABLE`
 * a mitad de un test —y en MySQL eso provoca un *commit* implícito que se
 * lleva por delante el aislamiento de `RefreshDatabase`. Leyendo archivos, la
 * suite puede auditar un árbol de mentira con `--root` y comprobar que el
 * comando encuentra lo que tiene que encontrar. El esquema real ya lo
 * comprueban `TenantSchemaTest` y `PlatformSchemaTest`, contra la base de
 * datos migrada.
 *
 * Las cinco comprobaciones son las cuatro de RB-4, más RB-4c:
 *
 *  1. todo modelo de una tabla con `organization_id` usa `BelongsToOrganization`;
 *  2. todo índice único de una tabla del tenant incluye `organization_id`,
 *     salvo las excepciones de `GlobalUniqueExceptions`;
 *  3. la exclusión del scope —**en sus dos grafías**— solo aparece en las
 *     rutas permitidas de RB-4b;
 *  4. ninguna migración crea una tabla del tenant sin `organization_id` ni sin
 *     su índice;
 *  5. toda factory de un modelo del tenant usa `CreatesWithinTenant` (RB-4c).
 */
final class TenancyAudit extends Command
{
    /** @var string */
    protected $signature = 'tenancy:audit
        {--root= : Raíz del árbol que se audita; por defecto, la del proyecto}';

    /** @var string */
    protected $description = 'Audita el aislamiento multi-tenant sobre el código (REQ-QAX-008 RB-4)';

    /**
     * Rutas donde `withoutTenantScope()` está permitido (RB-4b, **lista
     * cerrada**).
     *
     * Añadir una cuarta exige modificar `REQ-QAX-008`, igual que añadir una
     * excepción a `GlobalUniqueExceptions`. La de `app/Auth` es la menos obvia
     * y la más necesaria: el login resuelve al usuario **antes** de conocer la
     * organización, y `users.email` es único global (`REQ-ORG-002` RB-3)
     * precisamente para que esa búsqueda sin filtro no pueda devolver el
     * usuario de otro negocio.
     *
     * @var array<string, string>
     */
    private const ALLOWED_SCOPE_EXCLUSION_PATHS = [
        'app/Queries/Platform' => 'El backoffice de plataforma ve a todas las organizaciones.',
        'app/Actions/Platform' => 'Las acciones de plataforma operan sin tenant.',
        'app/Auth/TenantAwareUserProvider.php' => 'El login ocurre antes de conocer la organización.',
        'app/Console/Commands' => 'Los comandos de mantenimiento recorren la plataforma entera.',
    ];

    /**
     * Archivo que **declara** la exclusión, en lugar de usarla.
     *
     * No es una ruta permitida más: es el mecanismo. `scopeWithoutTenantScope()`
     * tiene que llamar a `withoutGlobalScope()` en alguna parte, y ese sitio es
     * el trait. Contarlo como una violación obligaría a meter
     * `app/Domain/Tenancy` en la lista cerrada de RB-4b, que es justo lo que no
     * puede pasar: ahí dentro cabría cualquier consulta sin filtro.
     */
    private const SCOPE_DECLARATION_FILE = 'app/Domain/Tenancy/BelongsToOrganization.php';

    /**
     * Tablas que legítimamente no pertenecen al tenant, con su motivo.
     *
     * La regla se aplica al revés a propósito: **toda tabla creada se presume
     * del tenant** y tiene que llevar `organization_id`, salvo que esté aquí.
     * Una lista de tablas del tenant se olvidaría de actualizar el día que se
     * añade una nueva —que es el día en que hace falta—; esta, en cambio,
     * obliga a justificar por escrito cada tabla que se queda fuera.
     *
     * Las exentas *del tenant* que sí son del tenant —`catalog_subscriptions`
     * y `catalog_digest_dispatches`— no están aquí: están en
     * `GlobalUniqueExceptions::tables()`, que es donde vive esa excepción.
     *
     * @var array<string, string>
     */
    private const NON_TENANT_TABLES = [
        // Capa de plataforma ([05 §4] bloque 1).
        'platform_users' => 'Operadores de la plataforma, tabla separada por construcción (REQ-PLT-001 RB-1).',
        'plans' => 'Catálogo de planes, común a toda la plataforma (REQ-SUB-001).',
        'organizations' => 'Es el tenant; no está sujeta a él.',
        'exchange_rates' => 'Tasa de cambio única para toda la plataforma (REQ-FX-001).',
        'accounts' => 'Cuenta global del cliente final, anterior a cualquier organización (REQ-ACC-001).',
        'platform_settings' => 'Ajustes de la plataforma, no de un negocio (REQ-PLT-005).',

        // Framework y paquetes.
        'password_reset_tokens' => 'Tabla del framework, indexada por correo.',
        'sessions' => 'Tabla del framework.',
        'cache' => 'Tabla del framework.',
        'cache_locks' => 'Tabla del framework.',
        'jobs' => 'Tabla del framework.',
        'job_batches' => 'Tabla del framework.',
        'failed_jobs' => 'Tabla del framework.',
        'media' => 'Tabla de spatie/laravel-medialibrary: se ata a su modelo por relación polimórfica.',
        'activity_log' => 'Tabla de spatie/laravel-activitylog: se ata a su modelo por relación polimórfica.',
        'push_subscriptions' => 'Tabla de laravel-notification-channels/webpush: se ata a su notificable.',
    ];

    /**
     * Cabecera de una llamada de esquema, con o sin conexión intercalada.
     *
     * Las migraciones publicadas por paquetes escriben
     * `Schema::connection(config('…'))->create(…)`, así que reconocer solo
     * `Schema::create(` dejaría esa forma sin auditar **en silencio**, que es
     * la peor de las dos maneras de no comprobar algo.
     */
    private const SCHEMA_CALL = '/Schema::(?:[A-Za-z]+\(.*?\)\s*->\s*)?(create|table)';

    /**
     * Migraciones cuyo nombre de tabla no es literal y no se pueden auditar.
     *
     * @var list<string>
     */
    private array $unreadableMigrations = [];

    public function handle(): int
    {
        $root = $this->auditedRoot();

        $tables = $this->tablesFromMigrations($root);
        $models = $this->modelsFrom($root, $tables);

        $findings = [
            'RB-4.1 · modelos de una tabla con organization_id sin BelongsToOrganization' => $this->modelsWithoutTrait($models),
            'RB-4.2 · índices únicos del tenant sin organization_id' => $this->globalUniqueIndexes($tables),
            'RB-4.3 · exclusión del scope fuera de las rutas permitidas de RB-4b' => $this->scopeExclusions($root),
            'RB-4.4 · tablas del tenant sin organization_id o sin su índice' => $this->tenantTablesWithoutColumn($tables),
            'RB-4c · factories de modelos del tenant sin CreatesWithinTenant' => $this->factoriesWithoutTrait($root, $models),
        ];

        return $this->report($root, $findings);
    }

    /**
     * Imprime el informe y decide el código de salida.
     *
     * Cada violación nombra su archivo o su índice: un informe que solo dijera
     * "hay tres violaciones" obligaría a repetir a mano la búsqueda que el
     * comando acaba de hacer.
     *
     * @param  array<string, list<string>>  $findings
     */
    private function report(string $root, array $findings): int
    {
        $this->newLine();
        $this->line('  Auditoría de aislamiento multi-tenant · REQ-QAX-008 RB-4');
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
                '  %d violación(es) del aislamiento. REQ-QAX-008 RB-1 no admite excepciones ni baseline.',
                $total,
            ));

            return self::FAILURE;
        }

        $this->info('  Sin violaciones del aislamiento.');

        return self::SUCCESS;
    }

    /**
     * Raíz del árbol auditado.
     *
     * `--root` existe para la suite: auditar un árbol con violaciones
     * inyectadas es la única forma de saber que el comando las detecta, y
     * hacerlo sobre el repositorio dejaría archivos rotos si el test muriese a
     * mitad.
     */
    private function auditedRoot(): string
    {
        $root = $this->option('root');

        return rtrim(is_string($root) && $root !== '' ? $root : base_path(), '/\\');
    }

    // -------------------------------------------------------------------
    // RB-4.1 · el trait en todo modelo del tenant
    // -------------------------------------------------------------------

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

    // -------------------------------------------------------------------
    // RB-4.2 · todo índice único del tenant incluye organization_id
    // -------------------------------------------------------------------

    /**
     * La lista blanca se consulta a `GlobalUniqueExceptions` y no se copia
     * aquí: mantenida en dos sitios se desincronizaría, y lo haría en la
     * dirección peligrosa —el código con una excepción de más—.
     *
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

    // -------------------------------------------------------------------
    // RB-4.3 y RB-4b · la exclusión del scope, en sus dos grafías
    // -------------------------------------------------------------------

    /**
     * Busca **las dos grafías**, y esto no es una precaución teórica: lo
     * descubrió `T-008`. `TenantAwareUserProvider` escribe
     * `withoutGlobalScope(TenantScope::class)` y no `withoutTenantScope()`
     * porque es genérico sobre el modelo del guard y PHPStan nivel 6 rechaza
     * el scope local sobre un `Builder<Model>`. Buscar solo la grafía corta
     * deja ese archivo —y cualquier código genérico futuro— fuera del radar.
     *
     * Se busca sobre el código sin comentarios **ni literales de cadena**: el
     * mensaje de `MissingTenantContextException` nombra `withoutTenantScope()`
     * para explicar la salida, y los docblocks del trait y del scope también.
     *
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
                    $violations[] = sprintf('%s usa %s · fuera de la lista cerrada de RB-4b', $relative, $spelling);
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

    // -------------------------------------------------------------------
    // RB-4.4 · ninguna tabla del tenant sin su columna ni su índice
    // -------------------------------------------------------------------

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

    // -------------------------------------------------------------------
    // RB-4c · toda factory del tenant siembra dentro de una organización
    // -------------------------------------------------------------------

    /**
     * La guarda de creación de `BelongsToOrganization` ya lo exige de hecho:
     * sin `CreatesWithinTenant`, la factory lanza al persistir. Lo que esta
     * comprobación adelanta es el momento del fallo —ahora, y no cuando
     * alguien intente usar la factory— y el mensaje, que aquí dice qué falta.
     *
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

    // -------------------------------------------------------------------
    // Lectura del árbol
    // -------------------------------------------------------------------

    /**
     * Tablas declaradas por las migraciones, con lo que hace falta saber de
     * cada una.
     *
     * `Schema::table()` cuenta igual que `Schema::create()`: una migración de
     * alteración puede añadir tanto la columna como el índice único que la
     * olvida.
     *
     * @return array<string, array{file: string, created: bool, column: bool, indexed: bool, uniques: list<array{columns: list<string>, file: string}>}>
     */
    private function tablesFromMigrations(string $root): array
    {
        $tables = [];

        foreach ($this->phpFilesIn($root.'/database/migrations') as $file) {
            $relative = $this->relative($root, $file);
            $code = $this->sourceOf($file);

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

                foreach ($analysis['uniques'] as $columns) {
                    $tables[$table]['uniques'][] = ['columns' => $columns, 'file' => $relative];
                }
            }
        }

        return $tables;
    }

    /**
     * Bloques `Schema::create('x', …)` y `Schema::table('x', …)` de un
     * archivo, emparejados con su tabla.
     *
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

    /**
     * Cuerpo del *closure* que sigue a esa posición, por conteo de llaves.
     */
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
     * Lo que interesa de un bloque de esquema: si declara `organization_id`,
     * si algún índice empieza por ella, y qué índices únicos crea.
     *
     * Se recorre sentencia a sentencia para que las tres formas de declarar un
     * único cuenten igual: `unique(['a','b'])`, `unique('a')` y el encadenado
     * `string('email')->unique()`.
     *
     * @return array{column: bool, indexed: bool, uniques: list<list<string>>}
     */
    private function analyseBlock(string $block): array
    {
        $column = false;
        $indexed = false;
        $uniques = [];

        foreach (explode(';', $block) as $statement) {
            $mentionsTenant = in_array(TenantScope::COLUMN, $this->quotedStrings($statement), true);
            $column = $column || $mentionsTenant;

            if ($mentionsTenant && (str_contains($statement, '->constrained(') || str_contains($statement, '->foreign('))) {
                $indexed = true;
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

        return ['column' => $column, 'indexed' => $indexed, 'uniques' => $uniques];
    }

    /**
     * Columnas sobre las que actúa un `->index(` o un `->unique(` de esa
     * sentencia, o `null` si la sentencia no lo contiene.
     *
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

        // Forma encadenada: el único cae sobre la columna que abre la
        // sentencia, `$table->string('email')->unique()`.
        $chained = $this->quotedStrings(substr($statement, 0, $position));

        return $chained === [] ? ['(columna encadenada sin nombre)'] : [$chained[0]];
    }

    /**
     * Modelos del árbol, con la tabla que les toca y si llevan el trait.
     *
     * La tabla sale de `$table` si está declarada y, si no, de la convención
     * de Eloquent. Es la misma regla que aplica el framework, así que un
     * modelo que no declare nada se audita igual que se comporta.
     *
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

    /**
     * Nombre de la clase declarada en ese código, si hay alguna.
     */
    private function classNameIn(string $code): ?string
    {
        return preg_match('/\bclass\s+([A-Za-z_][A-Za-z0-9_]*)/', $code, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Indica si la clase declarada usa ese trait.
     *
     * Se busca a partir de la declaración de la clase para no confundir el
     * `use` del cuerpo con el `import` de la cabecera: un modelo puede
     * importar el trait y no aplicarlo, y eso es exactamente la violación que
     * se persigue.
     */
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
     * Literales de cadena de un fragmento de código, en orden.
     *
     * @return list<string>
     */
    private function quotedStrings(string $fragment): array
    {
        if (preg_match_all('/([\'"])([^\'"]*)\1/', $fragment, $matches) === 0) {
            return [];
        }

        return $matches[2];
    }

    /**
     * Código de un archivo sin comentarios y, si se pide, sin literales de
     * cadena.
     *
     * Va por el *tokenizer* y no por expresiones regulares porque la
     * diferencia entre nombrar `withoutTenantScope()` en un docblock y
     * llamarlo es justo la que decide si algo es una violación.
     */
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
     * Archivos PHP de un directorio, en profundidad y en orden estable.
     *
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Ruta del archivo relativa a la raíz auditada, con separadores POSIX para
     * que el informe se lea igual en Windows y en CI.
     */
    private function relative(string $root, string $file): string
    {
        $normalised = str_replace('\\', '/', $root).'/';

        return str_starts_with($file, $normalised)
            ? substr($file, strlen($normalised))
            : $file;
    }
}
