<?php

declare(strict_types=1);

namespace Database\Factories\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * Permite que la factory de un modelo del tenant persista dentro de una
 * organización.
 *
 * `BelongsToOrganization` exige un tenant fijado para crear: sin él, guardar
 * lanza `MissingTenantContextException` (`REQ-ORG-002` SP-3). Eso vale para el
 * código de aplicación, donde siempre hay un middleware o un job que lo fija,
 * pero deja a las factories sin forma de sembrar nada.
 *
 * Este trait resuelve la organización en la que persistir y envuelve el
 * guardado en `TenantContext::runFor()`, que restaura el contexto anterior al
 * salir. El orden de resolución importa y es deliberado:
 *
 *  1. **La organización activa**, si la hay. Un `withinOrganization($a, …)` que
 *     por dentro pidiera sembrar en `$b` es un error del test, no una
 *     intención: dejarlo pasar escondería el fallo que las guardas de `T-005`
 *     existen para hacer visible, así que aquí gana el contexto y el trait
 *     lanza `CrossTenantRelationException`.
 *  2. **La organización que traiga el propio modelo**, por atributo explícito
 *     o por `for()`. Es el caso de sembrar sin contexto previo.
 *  3. **Una organización nueva**, para el test que solo necesita "un usuario"
 *     y no sabe nada de tenants. Es lo que mantiene en pie el andamiaje
 *     heredado del starter kit.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Factories\Factory
 */
trait CreatesWithinTenant
{
    /**
     * Persiste los modelos dentro de su organización.
     *
     * Se interpone en `store()` y no en `create()` porque es el único punto
     * por el que pasan todas las vías —`create`, `createQuietly`,
     * `createMany` y los hijos de `has()`— y porque en ese momento los
     * modelos ya tienen sus atributos resueltos.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $results
     */
    protected function store(Collection $results): void
    {
        $organization = $this->resolveTenantOrganization($results);

        app(TenantContext::class)->runFor($organization, function () use ($results): void {
            parent::store($results);
        });
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $results
     */
    private function resolveTenantOrganization(Collection $results): Organization
    {
        $context = app(TenantContext::class);

        if ($context->hasOrganization()) {
            return $context->organization();
        }

        $declared = $results
            ->map(fn ($model): mixed => $model->getAttribute('organization_id'))
            ->first(fn ($organizationId): bool => $organizationId !== null);

        if (is_numeric($declared)) {
            return Organization::query()->findOrFail((int) $declared);
        }

        return Organization::factory()->create();
    }
}
