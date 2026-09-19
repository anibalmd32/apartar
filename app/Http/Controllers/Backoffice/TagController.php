<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Catalog\UpsertTagAction;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\UpsertTagRequest;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las etiquetas del inventario (`REQ-BOF-023`).
 *
 * Más corto que el de categorías, y la diferencia **es** el requisito: no hay
 * reasignación porque no hace falta —una etiqueta se desasocia sin que el
 * producto pierda nada (RB-5)— y no hay orden manual porque las etiquetas no
 * se ordenan a mano, que es por lo que su tabla no tiene `position`.
 *
 * Tampoco hay un `422` al borrar: **se borra siempre**, incluso si alguien
 * tiene ese filtro en un enlace ya compartido (SP-4). El filtro se ignora al
 * abrirlo, que es lo que `REQ-CAT-006` SP-4 ya decidió para el catálogo
 * público.
 *
 * El `{tag}` se resuelve ya filtrado por el tenant: el identificador de otra
 * organización da `404` y no `403` (`REQ-ORG-002` RB-6).
 */
final class TagController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): Response
    {
        Gate::forUser($request->user())->authorize('manageProducts', $this->context->organization());

        return Inertia::render('backoffice/taxonomy/Tags', [
            'tags' => $this->tags(),
        ]);
    }

    public function store(UpsertTagRequest $request, UpsertTagAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $upsert->handle($request->name(), $request->slug(), $actor);

        return $this->back('Etiqueta creada.');
    }

    public function update(UpsertTagRequest $request, Tag $tag, UpsertTagAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $upsert->handle($request->name(), $request->slug(), $actor, $tag);

        return $this->back('Etiqueta guardada.');
    }

    public function destroy(Request $request, Tag $tag, UpsertTagAction $upsert): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $upsert->delete($tag, $actor);

        return $this->back('Etiqueta eliminada. Los productos que la llevaban siguen ahí, sin ella.');
    }

    /**
     * Las etiquetas tal como las pinta la pantalla, por nombre y con su
     * conteo (RB-7).
     *
     * @return list<array<string, mixed>>
     */
    private function tags(): array
    {
        return Tag::query()
            ->ordered()
            ->get()
            ->map(fn (Tag $tag): array => [
                'id' => (int) $tag->getKey(),
                'name' => $tag->name,
                'slug' => $tag->slug,
                'productsCount' => (int) $tag->getAttribute('products_count'),
            ])
            ->values()
            ->all();
    }

    private function back(string $status): RedirectResponse
    {
        return to_route('tags.index')->with('status', $status);
    }
}
