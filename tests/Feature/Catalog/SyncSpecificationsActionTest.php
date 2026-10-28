<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\ProductData;
use App\Actions\Product\SyncSpecificationsAction;
use App\Actions\Product\UpsertProductAction;
use App\Domain\Money\Money;
use App\Domain\Tenancy\CrossTenantRelationException;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSpecification;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SyncSpecificationsActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_writes_contiguous_positions_from_one(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            'Potencia',
            'Velocidades',
            'Material',
        ]);

        expect($this->rowsOf($product))->toBe([
            ['position' => 1, 'text' => 'Potencia'],
            ['position' => 2, 'text' => 'Velocidades'],
            ['position' => 3, 'text' => 'Material'],
        ]);
    }

    public function test_it_collapses_whitespace_and_drops_empty_items(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, ["  Pantalla de   6,5\tpulgadas ", '', '   ', 'Batería de 5000 mAh']);

        expect($this->rowsOf($product))->toBe([
            ['position' => 1, 'text' => 'Pantalla de 6,5 pulgadas'],
            ['position' => 2, 'text' => 'Batería de 5000 mAh'],
        ]);
    }

    public function test_empty_items_do_not_count_toward_the_limit(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [...$this->rows(SyncSpecificationsAction::MAX_ROWS), '', ' ']);

        expect(ProductSpecification::query()->count())->toBe(SyncSpecificationsAction::MAX_ROWS);
    }

    public function test_reordering_renumbers_the_whole_sheet(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            'Primera',
            'Segunda',
            'Tercera',
        ]);

        $this->sync()->handle($product, [
            'Tercera',
            'Primera',
        ]);

        expect($this->rowsOf($product))->toBe([
            ['position' => 1, 'text' => 'Tercera'],
            ['position' => 2, 'text' => 'Primera'],
        ]);
    }

    public function test_the_replacement_reinserts_every_row(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, ['Material']);

        $before = ProductSpecification::query()->sole()->getKey();

        $this->sync()->handle($product, ['Material']);

        expect(ProductSpecification::query()->count())->toBe(1);
        expect(ProductSpecification::query()->sole()->getKey())->not->toBe($before);
    }

    public function test_an_empty_list_deletes_every_row(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            'Material',
            'Peso',
        ]);

        $this->sync()->handle($product, []);

        expect(ProductSpecification::query()->count())->toBe(0);
    }

    public function test_the_last_list_wins_without_merging(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, [
            'Material',
            'Peso',
        ]);

        $this->sync()->handle($product, ['Color']);

        expect(array_column($this->rowsOf($product), 'text'))->toBe(['Color']);
    }

    public function test_it_accepts_thirty_rows_and_rejects_thirty_one(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS));

        expect(ProductSpecification::query()->count())->toBe(SyncSpecificationsAction::MAX_ROWS);

        try {
            $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS + 1));
            $this->fail('Treinta y una filas deberían rechazarse.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('specifications');
        }
    }

    public function test_the_row_limit_is_checked_before_deleting(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(3));

        try {
            $this->sync()->handle($product, $this->rows(SyncSpecificationsAction::MAX_ROWS + 1));
        } catch (ValidationException) {
        }

        expect(ProductSpecification::query()->count())->toBe(3);
    }

    public function test_a_failure_halfway_keeps_the_previous_sheet_intact(): void
    {
        $product = Product::factory()->create(['sku' => 'BOSCH-01']);

        $this->sync()->handle($product, [
            'Potencia',
            'Material',
        ]);

        $foreignTag = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): Tag => Tag::factory()->named('Del vecino')->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            app(UpsertProductAction::class)->handle(
                $this->productData(['Color'], [(int) $foreignTag->getKey()]),
                $this->actor,
                $product,
            );
            $this->fail('La etiqueta ajena debería rechazar el guardado.');
        } catch (CrossTenantRelationException) {
        }

        expect(array_column($this->rowsOf($product), 'text'))->toBe(['Potencia', 'Material']);
    }

    public function test_the_sheet_is_saved_with_the_product(): void
    {
        $product = app(UpsertProductAction::class)->handle(
            $this->productData([
                'Potencia',
                'Material',
            ]),
            $this->actor,
        );

        expect(array_column($this->rowsOf($product), 'text'))->toBe(['Potencia', 'Material']);
    }

    public function test_a_null_sheet_leaves_the_rows_alone(): void
    {
        $product = app(UpsertProductAction::class)->handle(
            $this->productData(['Material']),
            $this->actor,
        );

        app(UpsertProductAction::class)->handle($this->productData(null), $this->actor, $product);

        expect(ProductSpecification::query()->count())->toBe(1);
    }

    public function test_every_row_belongs_to_the_active_organization(): void
    {
        $product = Product::factory()->create();

        $this->sync()->handle($product, $this->rows(4));

        expect(ProductSpecification::query()->pluck('organization_id')->unique()->all())
            ->toBe([(int) $this->organization->getKey()]);
    }

    public function test_it_persists_the_value_verbatim(): void
    {
        $product = Product::factory()->create();
        $attack = '<script>alert("xss")</script>';

        $this->sync()->handle($product, [$attack]);

        expect(ProductSpecification::query()->sole()->text)->toBe($attack);
    }

    /**
     * @return list<array{position: int, text: string}>
     */
    private function rowsOf(Product $product): array
    {
        return ProductSpecification::query()
            ->where('product_id', $product->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ProductSpecification $row): array => [
                'position' => $row->position,
                'text' => $row->text,
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function rows(int $count): array
    {
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = 'Característica '.$number;
        }

        return $rows;
    }

    /**
     * @param  list<string>|null  $specifications
     * @param  list<int>  $tagIds
     */
    private function productData(?array $specifications, array $tagIds = []): ProductData
    {
        return new ProductData(
            name: 'Taladro Bosch',
            sku: 'BOSCH-01',
            slug: null,
            description: 'Un taladro.',
            price: Money::fromDecimalString('250.00'),
            compareAtPrice: null,
            downPayment: Money::fromDecimalString('50.00'),
            installmentsCount: 4,
            frequencyDays: 15,
            stock: 10,
            tagIds: $tagIds,
            specifications: $specifications,
        );
    }

    private function sync(): SyncSpecificationsAction
    {
        return app(SyncSpecificationsAction::class);
    }
}
