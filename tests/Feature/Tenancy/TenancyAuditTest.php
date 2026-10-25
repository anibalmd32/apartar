<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\GlobalUniqueExceptions;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

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

    public function test_the_project_passes_its_own_audit(): void
    {
        $this->assertSame(0, Artisan::call('tenancy:audit'), Artisan::output());
    }

    public function test_the_clean_fixture_passes(): void
    {
        $this->assertSame(0, $this->audit(), 'El árbol de partida ya traía una violación.');
    }

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

    public function test_it_finds_a_unique_chained_to_the_column(): void
    {
        $this->write('database/migrations/2030_01_01_000000_create_widgets_table.php', $this->widgetsMigration(
            "\$table->string('barcode')->unique();",
        ));

        $this->assertStringContainsString('UNIQUE(barcode)', $this->failingAudit());
    }

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

        $this->assertSame(0, $this->audit(), 'Una ruta permitida se marcó como violación.');
    }

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

    public function test_the_whitelist_still_holds_exactly_two_indexes_and_four_tables(): void
    {
        $this->assertSame(
            ['catalogs.slug', 'layaways.code'],
            array_keys(GlobalUniqueExceptions::indexes()),
        );

        $this->assertSame(
            ['catalog_subscriptions', 'catalog_digest_dispatches', 'product_tag', 'catalog_product'],
            array_keys(GlobalUniqueExceptions::tables()),
        );
    }

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

    private function audit(): int
    {
        return Artisan::call('tenancy:audit', ['--root' => $this->root]);
    }

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

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->deleteTree($path) : unlink($path);
        }

        rmdir($directory);
    }
}
