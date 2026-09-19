<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Catalog\ReassignCategoryProductsAction;
use App\Actions\Catalog\ReorderCategoriesAction;
use App\Actions\Catalog\UpsertCategoryAction;
use App\Domain\Catalog\CategoryInUseException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\ReassignCategoryProductsRequest;
use App\Http\Requests\Backoffice\ReorderCategoriesRequest;
use App\Http\Requests\Backoffice\UpsertCategoryRequest;
use App\Models\Category;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las categorías del inventario (`REQ-BOF-023`).
 *
 * Fino como el de cuentas de cobro y por lo mismo: cada operación es una línea
 * a su acción, que es quien puede garantizar la transacción y la contigüidad
 * de `position`. Lo que este archivo decide es **qué se ve** y **dónde aterriza
 * cada error**.
 *
 * Los dos `422` de la taxonomía llegan como `ValidationException` y van a
 * campos distintos a propósito: el del borrado a `category_id` —el desplegable
 * de destino de la reasignación, que es donde está la salida— y el del enlace
 * bloqueado a `slug`, que es el campo que no se puede cambiar. Un `422`
 * genérico diría «no se puede» y dejaría a quien lo lee donde estaba.
 *
 * El `{category}` se resuelve **ya filtrado** por el tenant, así que el
 * identificador de otra organización da `404` y no `403` (SP-7,
 * `REQ-ORG-002` RB-6): no se confirma que exista.
 */
final class CategoryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());

        return Inertia::render('backoffice/taxonomy/Categories', [
            'categories' => $this->categories(),
        ]);
    }

    public function store(UpsertCategoryRequest $request, UpsertCategoryAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $upsert->handle($request->name(), $request->slug(), $actor);

        return $this->back('Categoría creada.');
    }

    /**
     * Guarda el nombre y, si se pidió, el enlace (RB-6, SP-3).
     *
     * @throws ValidationException
     */
    public function update(
        UpsertCategoryRequest $request,
        Category $category,
        UpsertCategoryAction $upsert,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->handle($request->name(), $request->slug(), $actor, $category);
        } catch (CategoryInUseException $exception) {
            throw ValidationException::withMessages([$exception->field => $exception->getMessage()]);
        }

        return $this->back('Categoría guardada.');
    }

    /**
     * Borra la categoría, o explica por qué no (SP-1).
     *
     * @throws ValidationException
     */
    public function destroy(Request $request, Category $category, UpsertCategoryAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->delete($category, $actor);
        } catch (CategoryInUseException $exception) {
            // Al campo del destino de la reasignación y no a uno propio: el
            // mensaje dice «reasígnalos a otra categoría» y ese es justo el
            // control que hay que mirar al leerlo.
            throw ValidationException::withMessages(['category_id' => $exception->getMessage()]);
        }

        return $this->back('Categoría eliminada.');
    }

    /**
     * Mueve los productos de esta categoría a otra, antes de borrarla (RB-4).
     */
    public function reassign(
        ReassignCategoryProductsRequest $request,
        Category $category,
        ReassignCategoryProductsAction $reassign,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();

        $moved = $reassign->handle($category, $request->targetId(), $actor);

        return $this->back(sprintf(
            '%s movido%s fuera de «%s».',
            $moved === 1 ? '1 producto' : $moved.' productos',
            $moved === 1 ? '' : 's',
            $category->name,
        ));
    }

    /**
     * Guarda el orden tras el arrastre (RB-2).
     */
    public function reorder(ReorderCategoriesRequest $request, ReorderCategoriesAction $reorder): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $reorder->handle($request->orderedIds(), $actor);

        return $this->back('Orden guardado.');
    }

    /**
     * Las categorías tal como las pinta la pantalla, **en su orden y con su
     * conteo** (RB-2, RB-7).
     *
     * La consulta no lleva un solo `where` de organización: el global scope ya
     * filtra (`REQ-ORG-002` RB-1). El orden y el conteo salen juntos de
     * `scopeOrdered()`, que es donde está escrito qué entra en la cifra.
     *
     * @return list<array<string, mixed>>
     */
    private function categories(): array
    {
        return Category::query()
            ->ordered()
            ->get()
            ->map(fn (Category $category): array => [
                'id' => (int) $category->getKey(),
                'name' => $category->name,
                'slug' => $category->slug,
                'position' => $category->position,
                'productsCount' => (int) $category->getAttribute('products_count'),
            ])
            ->values()
            ->all();
    }

    private function back(string $status): RedirectResponse
    {
        return to_route('categories.index')->with('status', $status);
    }
}
