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
     * @throws ValidationException
     */
    public function destroy(Request $request, Category $category, UpsertCategoryAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $upsert->delete($category, $actor);
        } catch (CategoryInUseException $exception) {
            throw ValidationException::withMessages(['category_id' => $exception->getMessage()]);
        }

        return $this->back('Categoría eliminada.');
    }

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

    public function reorder(ReorderCategoriesRequest $request, ReorderCategoriesAction $reorder): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $reorder->handle($request->orderedIds(), $actor);

        return $this->back('Orden guardado.');
    }

    /**
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
