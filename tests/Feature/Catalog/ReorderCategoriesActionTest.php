<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Catalog\ReorderCategoriesAction;
use App\Models\Category;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El guardado del orden manual (`REQ-BOF-023` RB-2, SP-5).
 *
 * La aritmética vive en `CategoryOrder` y tiene su propio archivo; lo que se
 * fija aquí es lo que la acción añade encima: quién puede guardar el orden y
 * que **gana el último**, que es una decisión del requisito y no una omisión.
 */
final class ReorderCategoriesActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_persists_the_requested_order(): void
    {
        $ids = $this->seedNamed('A', 'B', 'C');

        $this->reorder()->handle([$ids['B'], $ids['C'], $ids['A']], $this->actor);

        $this->assertSame(['B', 'C', 'A'], $this->names());
    }

    /**
     * SP-5 · **gana el último.** Dos personas arrastrando la misma lista no
     * tienen un conflicto que resolver, tienen un orden que acaba siendo el de
     * quien soltó después; montar bloqueo optimista sería rechazarle el
     * guardado a alguien que no hizo nada malo.
     */
    public function test_the_last_save_wins(): void
    {
        $ids = $this->seedNamed('A', 'B', 'C');

        $this->reorder()->handle([$ids['C'], $ids['B'], $ids['A']], $this->actor);
        $this->reorder()->handle([$ids['A'], $ids['C'], $ids['B']], $this->actor);

        $this->assertSame(['A', 'C', 'B'], $this->names());
    }

    /**
     * Una lista vacía no borra el orden ni falla: significa «no cambies
     * nada», y lo único que hace es dejar las posiciones contiguas.
     */
    public function test_an_empty_list_only_compacts(): void
    {
        $this->seedNamed('A', 'B');

        Category::query()->where('name', 'B')->update(['position' => 40]);

        $this->reorder()->handle([], $this->actor);

        $this->assertSame(['A', 'B'], $this->names());
    }

    public function test_a_member_may_save_the_order(): void
    {
        $ids = $this->seedNamed('A', 'B');
        $member = User::factory()->create();

        $this->reorder()->handle([$ids['B'], $ids['A']], $member);

        $this->assertSame(['B', 'A'], $this->names());
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $ids = $this->seedNamed('A', 'B');

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        $this->expectException(AuthorizationException::class);

        $this->reorder()->handle([$ids['B'], $ids['A']], $foreignOwner);
    }

    /**
     * @return array<string, int>
     */
    private function seedNamed(string ...$names): array
    {
        $ids = [];
        $position = 1;

        foreach ($names as $name) {
            $ids[$name] = (int) Category::factory()->named($name)->create(['position' => $position])->getKey();
            $position++;
        }

        return $ids;
    }

    /**
     * @return list<string>
     */
    private function names(): array
    {
        return Category::query()->ordered()->pluck('name')->all();
    }

    private function reorder(): ReorderCategoriesAction
    {
        return app(ReorderCategoriesAction::class);
    }
}
