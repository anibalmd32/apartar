<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\FullTextTerm;
use Illuminate\Http\Request;

/**
 * Lo que el cliente pidió ver del catálogo: búsqueda, categoría, etiquetas y
 * orden (`REQ-CAT-006` RB-4 a RB-6, SP-1, SP-4, SP-5; `REQ-CAT-002`).
 *
 * El estado **vive en la query string** (RB-6) y esta clase es su lectura
 * normalizada. Normalizada quiere decir que lo que no se puede aplicar no se
 * aplica, y no rompe: un enlace compartido la semana pasada tiene que abrirse
 * aunque el negocio haya borrado la etiqueta desde entonces.
 *
 * ## Dos pasos, y el segundo es el que cuenta
 *
 * `fromRequest()` solo mira la **forma**: que `category` sea un slug, que
 * `tags` sea una lista de slugs, que `sort` esté en la lista blanca, que `q`
 * sobreviva al saneado. `restrictedTo()` mira el **fondo**: solo sobrevive la
 * categoría o la etiqueta que el panel ofrece, es decir, la que tiene
 * productos visibles en este catálogo (`CatalogFacetsQuery`). La misma lista
 * que se pinta es la que se acepta, y eso es lo que resuelve de una vez SP-1
 * (no existe o no está en este catálogo), SP-4 (borrada con la URL ya
 * compartida) y SP-5 (de otra organización: el *global scope* hace que no
 * exista), sin tres comprobaciones distintas. Lo que se descarta así deja
 * `ignored` a `true`, y la página lo dice («con un aviso», SP-1).
 *
 * El orden y el término inválidos **no** marcan `ignored`: el `sort` que no
 * existe cae a su defecto (SP-3) y el término vacío tras sanear se ignora
 * (`REQ-CAT-002` SP-1), los dos en silencio porque no hay nada que el cliente
 * haya elegido y haya perdido.
 */
final readonly class CatalogFilters
{
    /**
     * Etiquetas que se leen de la URL. El panel no ofrece más de las que hay,
     * y lo que pase de aquí es una URL fabricada a mano.
     */
    public const MAX_TAGS = 20;

    /**
     * La forma de un slug de la taxonomía (`TaxonomySlug`): minúsculas,
     * dígitos y guiones, hasta el largo de la columna.
     */
    private const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private const MAX_SLUG_LENGTH = 120;

    /**
     * @param  list<string>  $tags  slugs, sin repetir, en el orden en que llegaron
     * @param  CatalogSort|null  $sort  el orden **explícito**; `null` es «el de defecto»
     */
    public function __construct(
        public ?FullTextTerm $term = null,
        public ?string $category = null,
        public array $tags = [],
        public ?CatalogSort $sort = null,
        public bool $ignored = false,
    ) {}

    /**
     * Los filtros de la petición, comprobada solo su forma.
     */
    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $requested = $request->query('tags');

        // `tags=taladros` en vez de `tags[]=taladros`: la misma petición
        // escrita a mano, y se entiende.
        $requested = is_string($requested) ? [$requested] : (is_array($requested) ? $requested : []);
        $tags = self::slugs($requested);

        return new self(
            term: FullTextTerm::sanitize($request->query('q')),
            category: self::isSlug($category) ? $category : null,
            tags: $tags,
            sort: CatalogSort::whitelist()->resolve($request->query('sort')),
            // Lo que no tiene forma de slug tampoco está en el catálogo: es el
            // mismo aviso que el de `restrictedTo()`.
            ignored: ($category !== null && $category !== '' && ! self::isSlug($category)) || count($tags) < count($requested),
        );
    }

    /**
     * Los mismos filtros, sin la categoría ni las etiquetas que este catálogo
     * no ofrece.
     *
     * @param  list<string>  $categories  los slugs de las categorías presentes
     * @param  list<string>  $tags  los slugs de las etiquetas presentes
     */
    public function restrictedTo(array $categories, array $tags): self
    {
        $category = $this->category !== null && in_array($this->category, $categories, true) ? $this->category : null;
        $kept = array_values(array_intersect($this->tags, $tags));

        return new self(
            term: $this->term,
            category: $category,
            tags: $kept,
            sort: $this->sort,
            ignored: $this->ignored || $category !== $this->category || count($kept) !== count($this->tags),
        );
    }

    /**
     * El orden que de verdad se aplica (`REQ-CAT-002` RB-4).
     *
     * El explícito manda; sin él, relevancia **solo** si el índice contestó
     * —un `LIKE` no tiene puntuación por la que ordenar— y el curado en
     * cualquier otro caso (`REQ-CAT-006` RB-5).
     */
    public function effectiveSort(SearchMode $mode): CatalogSort
    {
        if ($this->sort !== null) {
            return $this->sort;
        }

        return $this->term !== null && $mode === SearchMode::FullText ? CatalogSort::Relevance : CatalogSort::Curated;
    }

    /**
     * El contrato `CatalogFilters` de la página, tal como se aplicó.
     *
     * @return array{q: string|null, category: string|null, tags: list<string>, sort: string|null, ignored: bool}
     */
    public function toArray(): array
    {
        return [
            'q' => $this->term?->text,
            'category' => $this->category,
            'tags' => $this->tags,
            'sort' => $this->sort?->value,
            'ignored' => $this->ignored,
        ];
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function isSlug(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= self::MAX_SLUG_LENGTH && preg_match(self::SLUG, $value) === 1;
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function slugs(array $values): array
    {
        $slugs = array_values(array_unique(array_filter($values, self::isSlug(...))));

        return array_slice($slugs, 0, self::MAX_TAGS);
    }
}
