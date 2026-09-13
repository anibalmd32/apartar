<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\GlobalUniqueExceptions;
use Illuminate\Support\Facades\Artisan;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Tests\TestCase;

/**
 * `tenancy:audit` (`REQ-QAX-008` RB-4, RB-4b y RB-4c).
 *
 * Lo que se prueba aquí **no** es que el comando salga con código 0 sobre el
 * código actual: eso también pasaría si no comprobara nada. Se le inyecta cada
 * una de las cinco clases de violación en un árbol de mentira y se exige que
 * la encuentre y la nombre.
 *
 * El árbol vive en un directorio temporal y se construye archivo a archivo
 * porque el comando es estático: no carga clases ni consulta la base de datos,
 * lee texto. Inyectar las violaciones en el repositorio sería la alternativa, y
 * dejaría archivos rotos detrás el día que un test muriese a mitad.
 *
 * Cada caso parte del mismo árbol **limpio** y rompe una sola cosa. El primer
 * test comprueba que ese árbol de partida pasa la auditoría, sin lo cual
 * cualquiera de los demás podría estar pasando por el motivo equivocado.
 */
final class TenancyAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/tenancy-audit-'.bin2hex(random_bytes(6));

        $this->writeCleanTree();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);

        parent::tearDown();
    }

    /**
     * El repositorio de verdad, que es lo que protege el gate en CI.
     */
    public function test_the_project_passes_its_own_audit(): void
    {
        $this->assertSame(0, Artisan::call('tenancy:audit'), Artisan::output());
    }

    public function test_the_clean_fixture_passes(): void
    {
        $this->assertSame(0, $this->audit(), 'El árbol de partida ya traía una violación.');
    }

    /**
     * RB-4.1 · un modelo cuya tabla lleva `organization_id` y que no usa el
     * trait: consultarlo devolvería las filas de todas las organizaciones.
     */
    public function test_it_finds_a_model_without_the_trait(): void
    {
        $this->write('app/Models/Widget.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models;

            use Illuminate\Database\Eloquent\Model;

            class Widget extends Model
            {
                protected $fillable = ['sku'];
            }
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('Widget', $output);
        $this->assertStringContainsString('BelongsToOrganization', $output);
        $this->assertStringContainsString('app/Models/Widget.php', $output);
    }

    /**
     * Y no se conforma con que el trait esté importado: el `use` de la cabecera
     * no aísla nada si falta el del cuerpo de la clase.
     */
    public function test_importing_the_trait_without_using_it_is_still_a_violation(): void
    {
        $this->write('app/Models/Widget.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models;

            use App\Domain\Tenancy\BelongsToOrganization;
            use Illuminate\Database\Eloquent\Model;

            class Widget extends Model
            {
                protected $fillable = ['sku'];
            }
            PHP);

        $this->assertStringContainsString('app/Models/Widget.php', $this->failingAudit());
    }

    /**
     * RB-4.2 · un índice único de una tabla del tenant que no incluye
     * `organization_id`: dos negocios no podrían tener el mismo SKU, y el
     * segundo se enteraría de la existencia del primero por el error.
     */
    public function test_it_finds_a_unique_index_without_the_tenant_column(): void
    {
        $this->write('database/migrations/2030_01_01_000000_create_widgets_table.php', $this->widgetsMigration(
            "\$table->unique('sku');",
        ));

        $output = $this->failingAudit();

        $this->assertStringContainsString('UNIQUE(sku)', $output);
        $this->assertStringContainsString('widgets', $output);
        $this->assertStringContainsString('2030_01_01_000000_create_widgets_table.php', $output);
    }

    /**
     * La otra grafía del mismo descuido: el único encadenado a la columna.
     */
    public function test_it_finds_a_unique_chained_to_the_column(): void
    {
        $this->write('database/migrations/2030_01_01_000000_create_widgets_table.php', $this->widgetsMigration(
            "\$table->string('barcode')->unique();",
        ));

        $this->assertStringContainsString('UNIQUE(barcode)', $this->failingAudit());
    }

    /**
     * RB-5 · la lista blanca se consulta, no se copia: un único global sobre
     * `layaways.code` está declarado en `GlobalUniqueExceptions` con su motivo
     * y el comando lo respeta sin tener su propia copia de la lista.
     */
    public function test_it_respects_the_declared_global_unique_exceptions(): void
    {
        $this->write('database/migrations/2030_01_01_000001_create_layaways_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('layaways', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                        $table->string('code');
                        $table->timestamps();

                        $table->unique('code');
                    });
                }
            };
            PHP);

        $this->assertSame(0, $this->audit(), 'La excepción declarada de layaways.code no se respetó.');
    }

    /**
     * RB-4.3 · la grafía corta, en un archivo fuera de la lista cerrada de
     * RB-4b.
     */
    public function test_it_finds_the_short_spelling_of_the_scope_exclusion(): void
    {
        $this->write('app/Http/Controllers/WidgetController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Http\Controllers;

            use App\Models\Widget;

            class WidgetController
            {
                public function index(): mixed
                {
                    return Widget::withoutTenantScope()->get();
                }
            }
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('app/Http/Controllers/WidgetController.php', $output);
        $this->assertStringContainsString('withoutTenantScope()', $output);
    }

    /**
     * Y la larga, que es la que `T-008` descubrió que se escapaba: el código
     * genérico sobre `Builder<Model>` no puede usar el scope local, así que
     * escribe `withoutGlobalScope(TenantScope::class)`. Buscar solo la grafía
     * corta habría dejado `TenantAwareUserProvider` fuera del radar.
     */
    public function test_it_finds_the_long_spelling_of_the_scope_exclusion(): void
    {
        $this->write('app/Queries/WidgetQuery.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Queries;

            use App\Domain\Tenancy\TenantScope;
            use App\Models\Widget;

            class WidgetQuery
            {
                public function all(): mixed
                {
                    return Widget::query()->withoutGlobalScope(TenantScope::class)->get();
                }
            }
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('app/Queries/WidgetQuery.php', $output);
        $this->assertStringContainsString('withoutGlobalScope(TenantScope::class)', $output);
    }

    /**
     * Las rutas permitidas de RB-4b siguen siéndolo, y con las dos grafías:
     * `app/Queries/Platform` porque el backoffice de plataforma ve a todas las
     * organizaciones, y `app/Auth/TenantAwareUserProvider` porque el login
     * resuelve al usuario antes de conocer la organización.
     */
    public function test_it_allows_the_closed_list_of_paths(): void
    {
        $this->write('app/Queries/Platform/EveryWidget.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Queries\Platform;

            use App\Models\Widget;

            class EveryWidget
            {
                public function get(): mixed
                {
                    return Widget::withoutTenantScope()->get();
                }
            }
            PHP);

        $this->write('app/Auth/TenantAwareUserProvider.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Auth;

            use App\Domain\Tenancy\TenantScope;

            class TenantAwareUserProvider
            {
                public function query(mixed $model): mixed
                {
                    return $model->newQuery()->withoutGlobalScope(TenantScope::class);
                }
            }
            PHP);

        $this->assertSame(0, $this->audit(), 'Una ruta permitida de RB-4b se marcó como violación.');
    }

    /**
     * Un docblock que nombra la exclusión no es una exclusión. Sin esta
     * distinción, el propio `MissingTenantContextException` —cuyo mensaje
     * explica la salida— sería una violación, y la comprobación acabaría
     * desactivada para poder pasar.
     */
    public function test_it_does_not_confuse_a_mention_with_a_call(): void
    {
        $this->write('app/Support/Documented.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Support;

            /**
             * Quien necesite ver todas las organizaciones pide withoutTenantScope().
             */
            class Documented
            {
                public function hint(): string
                {
                    return 'usa withoutTenantScope() si consultas desde plataforma';
                }
            }
            PHP);

        $this->assertSame(0, $this->audit(), 'Una mención en un comentario se contó como uso.');
    }

    /**
     * RB-4.4 · una tabla nueva sin `organization_id`. La presunción es la
     * contraria a la cómoda: toda tabla creada es del tenant salvo que esté
     * declarada fuera, así que olvidarse produce un fallo y no un silencio.
     */
    public function test_it_finds_a_tenant_table_without_the_column(): void
    {
        $this->write('database/migrations/2030_01_01_000002_create_gadgets_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('gadgets', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                        $table->timestamps();
                    });
                }
            };
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('gadgets', $output);
        $this->assertStringContainsString('organization_id', $output);
        $this->assertStringContainsString('2030_01_01_000002_create_gadgets_table.php', $output);
    }

    /**
     * La segunda mitad de RB-4.4: la columna sin índice. Filtrar por ella es lo
     * que hace toda consulta del sistema; sin índice, el aislamiento sería
     * correcto y el producto, inservible.
     */
    public function test_it_finds_a_tenant_column_without_its_index(): void
    {
        $this->write('database/migrations/2030_01_01_000003_create_gizmos_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('gizmos', function (Blueprint $table): void {
                        $table->id();
                        $table->unsignedBigInteger('organization_id');
                        $table->string('name');
                        $table->timestamps();
                    });
                }
            };
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('gizmos', $output);
        $this->assertStringContainsString('índice', $output);
    }

    /**
     * Y la tabla puente declarada en `GlobalUniqueExceptions::tables()` sigue
     * pudiendo prescindir de la columna: cruza la frontera del tenant a
     * propósito.
     */
    public function test_it_respects_the_tables_exempt_from_the_tenant_column(): void
    {
        $this->write('database/migrations/2030_01_01_000004_create_catalog_subscriptions_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('catalog_subscriptions', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignId('account_id')->constrained();
                        $table->timestamps();
                    });
                }
            };
            PHP);

        $this->assertSame(0, $this->audit(), 'La tabla puente declarada se marcó como violación.');
    }

    /**
     * RB-4c · la factory de un modelo del tenant sin `CreatesWithinTenant`.
     */
    public function test_it_finds_a_factory_without_creates_within_tenant(): void
    {
        $this->write('database/factories/WidgetFactory.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Database\Factories;

            use App\Models\Widget;
            use Illuminate\Database\Eloquent\Factories\Factory;

            class WidgetFactory extends Factory
            {
                protected $model = Widget::class;

                public function definition(): array
                {
                    return ['sku' => 'W-1'];
                }
            }
            PHP);

        $output = $this->failingAudit();

        $this->assertStringContainsString('WidgetFactory', $output);
        $this->assertStringContainsString('CreatesWithinTenant', $output);
        $this->assertStringContainsString('database/factories/WidgetFactory.php', $output);
    }

    /**
     * Las migraciones que nombran su tabla con una expresión quedan fuera del
     * alcance estático, y el informe **lo dice**. Una comprobación que no se
     * puede hacer y no se anuncia es indistinguible de una que pasa.
     */
    public function test_it_reports_the_migrations_it_cannot_read(): void
    {
        $this->write('database/migrations/2030_01_01_000005_create_dynamic_table.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::connection(config('widgets.connection'))->create(config('widgets.table'), function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->audit();

        $this->assertStringContainsString(
            '2030_01_01_000005_create_dynamic_table.php',
            Artisan::output(),
        );
    }

    /**
     * `REQ-QAX-008` RB-5 y `REQ-ORG-002` RB-3: la lista blanca no crece.
     *
     * La misma aserción vive en `tests/Unit`, y la repetición es deliberada:
     * el job `tenancy` de CI es independiente (RB-7) y no puede depender de que
     * otro job compruebe la lista de la que este comando deriva sus reglas.
     */
    public function test_the_whitelist_still_holds_exactly_three_indexes_and_two_tables(): void
    {
        $this->assertSame(
            ['users.email', 'catalogs.slug', 'layaways.code'],
            array_keys(GlobalUniqueExceptions::indexes()),
        );

        $this->assertSame(
            ['catalog_subscriptions', 'catalog_digest_dispatches'],
            array_keys(GlobalUniqueExceptions::tables()),
        );
    }

    // -------------------------------------------------------------------
    // El árbol de mentira
    // -------------------------------------------------------------------

    /**
     * Árbol mínimo que pasa la auditoría: una tabla del tenant bien formada,
     * su modelo con el trait y su factory con `CreatesWithinTenant`.
     */
    private function writeCleanTree(): void
    {
        $this->write(
            'database/migrations/2030_01_01_000000_create_widgets_table.php',
            $this->widgetsMigration("\$table->unique(['organization_id', 'sku']);"),
        );

        $this->write('app/Models/Widget.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Models;

            use App\Domain\Tenancy\BelongsToOrganization;
            use Illuminate\Database\Eloquent\Model;

            class Widget extends Model
            {
                use BelongsToOrganization;

                protected $fillable = ['sku'];
            }
            PHP);

        $this->write('database/factories/WidgetFactory.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Database\Factories;

            use App\Models\Widget;
            use Database\Factories\Concerns\CreatesWithinTenant;
            use Illuminate\Database\Eloquent\Factories\Factory;

            class WidgetFactory extends Factory
            {
                use CreatesWithinTenant;

                protected $model = Widget::class;

                public function definition(): array
                {
                    return ['sku' => 'W-1'];
                }
            }
            PHP);
    }

    /**
     * Migración de `widgets` con el índice único que se le pida.
     */
    private function widgetsMigration(string $unique): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    Schema::create('widgets', function (Blueprint \$table): void {
                        \$table->id();
                        \$table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                        \$table->string('sku');
                        \$table->timestamps();

                        {$unique}
                    });
                }
            };
            PHP;
    }

    /**
     * Ejecuta la auditoría sobre el árbol de mentira.
     */
    private function audit(): int
    {
        return Artisan::call('tenancy:audit', ['--root' => $this->root]);
    }

    /**
     * Ejecuta la auditoría exigiendo que falle, y devuelve su informe.
     */
    private function failingAudit(): string
    {
        $exit = $this->audit();
        $output = Artisan::output();

        $this->assertSame(1, $exit, 'La auditoría no detectó la violación inyectada: '.$output);

        return $output;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root.'/'.$relative;
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear '.$directory);
        }

        file_put_contents($path, $contents."\n");
    }

    private function deleteTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
