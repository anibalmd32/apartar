<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Catalog;
use Database\Factories\Concerns\CreatesWithinTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Catálogos para los tests.
 *
 * No declara `organization_id`: lo resuelve `CreatesWithinTenant` al
 * persistir, igual que las demás factories del tenant.
 *
 * **El `slug` sale con sufijo aleatorio y no del nombre**, y es la única
 * decisión de este archivo: `catalogs.slug` es único en **toda la
 * plataforma** ([05 §4.1]), así que dos catálogos sembrados en dos
 * organizaciones distintas con el mismo nombre chocarían — y ese choque
 * aparecería como un test de aislamiento en rojo por un motivo que no tiene
 * nada que ver con el aislamiento.
 *
 * Nace **en borrador** (`published_at` nulo), que es como nace un catálogo de
 * verdad (`REQ-CAT-022` RB-7): publicarlo es una acción explícita con sus seis
 * requisitos, y una factory que lo entregara publicado escondería ese paso.
 *
 * @extends Factory<Catalog>
 */
class CatalogFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<Catalog> */
    protected $model = Catalog::class;

    /**
     * @return array<model-property<Catalog>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Catálogo '.fake()->word(),
            'slug' => 'catalogo-'.Str::lower(Str::random(10)),
            'description' => null,
            'cover_path' => null,
            'is_listed' => true,
            'published_at' => null,
            'expires_at' => null,
        ];
    }

    /**
     * Publicado: el estado que hace que `PublishedCatalogs::exist()` responda
     * que sí y que bloquee el cambio de slug del negocio (`REQ-ORG-006` SP-5).
     */
    public function published(): static
    {
        return $this->state(fn (): array => ['published_at' => now()->subDay()]);
    }

    /**
     * Fuera de la vitrina pero accesible por enlace (`REQ-CAT-022` RB-3).
     *
     * `is_listed` no controla el acceso, y tener el estado con nombre es lo
     * que evita que un test lo confunda con «privado».
     */
    public function unlisted(): static
    {
        return $this->state(fn (): array => ['is_listed' => false]);
    }

    /**
     * Publicado y caducado: la campaña terminó y su URL responde `410`
     * (`REQ-CAT-021` SP-3).
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'published_at' => now()->subMonth(),
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Solo la fecha de caducidad, sin tocar la publicación.
     *
     * Existe para el cruce que la precedencia de `Catalog::visibility()` tiene
     * que resolver: un catálogo **nunca publicado** con la caducidad ya
     * pasada, que es borrador y no expirado.
     */
    public function expiresAt(DateTimeInterface $expiresAt): static
    {
        return $this->state(fn (): array => ['expires_at' => $expiresAt]);
    }
}
