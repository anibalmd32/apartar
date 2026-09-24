<?php

declare(strict_types=1);

namespace App\Support;

/**
 * El término de búsqueda del catálogo, ya saneado (`REQ-CAT-002` RB-2, RB-7,
 * SP-1, SP-3, SP-5).
 *
 * Llega de la query string de una superficie **pública y sin sesión**, así que
 * lo escribe cualquiera, y va a parar a un `MATCH … AGAINST` en modo booleano,
 * donde `+ - * " ( ) < > ~ @` son sintaxis. Esta clase es la frontera entre las
 * dos cosas: lo que sale de aquí ya no puede cambiar la consulta, solo
 * buscarse.
 *
 * ## Se escapa por lista blanca, no por lista negra
 *
 * MySQL no tiene forma de escapar un operador dentro de una expresión booleana:
 * no hay `\+`. Lo que se puede hacer es que el operador **no llegue**. Y en vez
 * de quitar los que se conocen —que es apostar a conocerlos todos, y `@` ya es
 * uno que casi nadie recuerda—, el término se parte en **palabras**: tramos de
 * letras, números y marcas diacríticas, y nada más. Todo lo demás es
 * separador. Así, los únicos operadores de la expresión final son los que pone
 * `booleanQuery()`, y el caso de RB-7 sale solo: «media-pulgada» se busca como
 * las dos palabras que el índice de InnoDB ya guardó, porque su tokenizador
 * también parte por el guion.
 *
 * ## Dos modos, y cuál decide la consulta
 *
 * `booleanQuery()` devuelve `null` cuando el índice no puede contestar: alguna
 * palabra tiene menos de tres caracteres (`innodb_ft_min_token_size`) o más de
 * 84 (el máximo), y el índice las **ignoraría en silencio**, que para quien
 * busca «tv» sería no encontrar nada y concluir que el negocio no lo vende.
 * Entonces la búsqueda es `LIKE` (`likePatterns()`), que es RB-2. La otra mitad
 * de la degradación —el índice sí puede contestar, pero no encuentra nada—
 * necesita ejecutar la consulta, y la decide `CatalogProductsQuery`.
 *
 * ## Palabras vacías
 *
 * «de», «la», «con» no distinguen un producto de otro, y el índice ya descarta
 * las suyas (la lista por defecto de InnoDB, en inglés, que incluye «de», «la»
 * y «en»). Se quitan aquí también, de los **dos** modos: si el `LIKE` las
 * exigiera, «llave de tubo» no encontraría «Llave para tubo» por culpa de una
 * palabra que el otro modo ni mira. Un término que se queda sin palabras —solo
 * signos, solo palabras vacías— **no es un término** y el parámetro se ignora
 * (SP-1): `sanitize()` devuelve `null`.
 */
final readonly class FullTextTerm
{
    /**
     * Caracteres que se conservan del término (SP-3): lo que pase se recorta
     * sin error.
     */
    public const MAX_LENGTH = 100;

    /**
     * `innodb_ft_min_token_size` y `innodb_ft_max_token_size`: fuera de ese
     * rango el índice no tiene la palabra.
     */
    private const MIN_INDEXED_LENGTH = 3;

    private const MAX_INDEXED_LENGTH = 84;

    /**
     * Las columnas del índice `FULLTEXT` de `products`, **en su orden**:
     * `MATCH` exige exactamente la lista del índice, o MySQL no lo encuentra.
     *
     * @var list<string>
     */
    public const COLUMNS = ['products.name', 'products.description', 'products.sku'];

    /**
     * Palabras vacías: la lista por defecto de InnoDB más las del español que
     * más se escriben en una búsqueda de catálogo.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        // INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD
        'a', 'about', 'an', 'are', 'as', 'at', 'be', 'by', 'com', 'de', 'en', 'for', 'from', 'how', 'i', 'in',
        'is', 'it', 'la', 'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'who',
        'will', 'with', 'und', 'www',
        // Español
        'el', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'y', 'e', 'o', 'u', 'del', 'al', 'con', 'para',
        'por', 'que', 'se', 'su', 'sus',
    ];

    /**
     * @param  string  $text  el término tal como se enseña: recortado y con los espacios normalizados
     * @param  non-empty-list<string>  $words  las palabras que se buscan
     */
    private function __construct(
        public string $text,
        public array $words,
    ) {}

    /**
     * El término de la petición, o `null` si no queda nada que buscar (SP-1).
     *
     * En este orden: solo cadenas —`?q[]=x` no es un término—; UTF-8 inválido
     * sustituido en vez de rechazado; espacios normalizados; recorte a
     * `MAX_LENGTH` **caracteres**, no bytes, para no partir una «ñ» (SP-3); y
     * las palabras, sin las vacías.
     */
    public static function sanitize(mixed $raw): ?self
    {
        if (! is_string($raw)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', mb_scrub($raw, 'UTF-8')));
        $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH, 'UTF-8'));

        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = array_values(array_filter(
            $words === false ? [] : $words,
            fn (string $word): bool => ! in_array(mb_strtolower($word, 'UTF-8'), self::STOPWORDS, true),
        ));

        return $words === [] ? null : new self($text, $words);
    }

    /**
     * La expresión para `MATCH … AGAINST (… IN BOOLEAN MODE)`, o `null` si el
     * índice no puede contestar y la búsqueda tiene que ser `LIKE` (RB-2).
     *
     * Cada palabra, **obligatoria y como prefijo**: `+taladro* +percutor*`.
     * Obligatoria porque quien escribe dos palabras está acotando, no
     * ampliando; como prefijo porque la búsqueda corre mientras se escribe
     * (RB-6) y «tala» tiene que ir encontrando «taladro».
     */
    public function booleanQuery(): ?string
    {
        foreach ($this->words as $word) {
            $length = mb_strlen($word, 'UTF-8');

            if ($length < self::MIN_INDEXED_LENGTH || $length > self::MAX_INDEXED_LENGTH) {
                return null;
            }
        }

        return implode(' ', array_map(fn (string $word): string => '+'.$word.'*', $this->words));
    }

    /**
     * Un patrón `LIKE %…%` por palabra, con `%`, `_` y `\` escapados.
     *
     * Por palabra y no uno para el término entero, por lo mismo que el modo
     * booleano: «taladro media-pulgada» tiene que encontrar «Taladro de media
     * pulgada», y un solo `%taladro media pulgada%` no lo haría.
     *
     * @return non-empty-list<string>
     */
    public function likePatterns(): array
    {
        return array_map(fn (string $word): string => '%'.addcslashes($word, '%_\\').'%', $this->words);
    }
}
