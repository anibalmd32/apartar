<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\TaxonomyByName;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class TaxonomyByNameTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    public function test_it_creates_the_category_when_the_name_is_new(): void
    {
        $category = $this->taxonomy()->category('Herramientas');

        $this->assertSame('Herramientas', $category->name);
        $this->assertSame('herramientas', $category->slug);
        $this->assertSame(1, Category::query()->count());
    }

    public function test_it_reuses_the_existing_category(): void
    {
        $existing = Category::factory()->named('Ropa')->create();

        $reused = $this->taxonomy()->category('Ropa');

        $this->assertTrue($reused->is($existing));
        $this->assertSame(1, Category::query()->count());
    }

    public function test_it_reuses_ignoring_capitalisation(): void
    {
        $existing = Category::factory()->named('Ropa')->create();

        $this->assertTrue($this->taxonomy()->category('ROPA')->is($existing));
        $this->assertSame(1, Category::query()->count());
    }

    public function test_it_collapses_the_whitespace_of_the_name(): void
    {
        $existing = Category::factory()->named('Ropa de niño')->create();

        $this->assertTrue($this->taxonomy()->category('  Ropa  de   niño ')->is($existing));
    }

    public function test_a_colliding_slug_gets_a_suffix(): void
    {
        Category::factory()->named('Ropa niño')->create();

        $second = $this->taxonomy()->category('Ropa-niño');

        $this->assertSame('ropa-nino-2', $second->slug);
        $this->assertSame(2, Category::query()->count());
    }

    public function test_a_simultaneous_request_for_the_same_name_reuses_the_winner(): void
    {
        $intercepted = false;

        Category::creating(function () use (&$intercepted): void {
            if ($intercepted) {
                return;
            }

            $intercepted = true;

            DB::table('categories')->insert([
                'organization_id' => $this->organization->getKey(),
                'name' => 'Ropa',
                'slug' => 'ropa',
                'position' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $category = $this->taxonomy()->category('Ropa');

        $this->assertTrue($intercepted);
        $this->assertSame('Ropa', $category->name);
        $this->assertSame(1, Category::query()->count());
    }

    public function test_it_reuses_an_existing_tag_and_creates_the_missing_one(): void
    {
        $existing = Tag::factory()->named('Oferta')->create();

        $this->assertTrue($this->taxonomy()->tag('Oferta')->is($existing));

        $created = $this->taxonomy()->tag('Novedad');

        $this->assertSame('novedad', $created->slug);
        $this->assertSame(2, Tag::query()->count());
    }

    public function test_it_does_not_reuse_the_category_of_another_organization(): void
    {
        $neighbour = $this->neighbourOrganization();

        $foreign = $this->withinOrganization(
            $neighbour,
            fn (): Category => Category::factory()->named('Ropa')->create(),
        );

        $this->actingAsOrganization($this->organization);

        $own = $this->taxonomy()->category('Ropa');

        $this->assertFalse($own->is($foreign));
        $this->assertSame((int) $this->organization->getKey(), $own->organization_id);
    }

    public function test_creating_inline_keeps_the_order_contiguous(): void
    {
        Category::factory()->named('Primera')->create(['position' => 7]);

        $created = $this->taxonomy()->category('Segunda');

        $this->assertSame([1, 2], Category::query()
            ->ordered()
            ->pluck('position')
            ->map(fn (mixed $position): int => (int) $position)
            ->all());

        $this->assertSame(2, $created->position);
    }

    private function taxonomy(): TaxonomyByName
    {
        return app(TaxonomyByName::class);
    }
}
