<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;
use App\Models\Tag;
use App\Support\TaxonomySlug;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Resuelve una categoría o una etiqueta **por su nombre**, creándola si no
 * existe (`REQ-BOF-023` RB-8, SP-6).
 *
 * Es la creación al vuelo desde el formulario de producto: quien escribe
 * «Ropa» en el campo de categoría está pidiendo *esa* categoría, y si ya
 * existe la respuesta correcta es dársela, no un `422` por duplicado. Esa es
 * la diferencia con el CRUD de `/backoffice/categorias`, donde el mismo nombre
 * **sí** es un error (SP-2): allí la intención es «crea una nueva», aquí es
 * «ponla en este producto».
 *
 * **Su llamante llega con `T-027`**, que es quien escribe el formulario de
 * producto. Vive aquí y no allí porque la regla es de `REQ-BOF-023` y porque
 * la carrera que resuelve se explica junto al índice que la hace posible.
 *
 * ## La carrera, y por qué el índice no basta por sí solo
 *
 * `UNIQUE(organization_id, name)` se añadió en `T-024` precisamente para esto:
 * comprobar «¿existe?» y luego crear son dos pasos, y entre ellos cabe otra
 * petición. Sin el índice, dos formularios de producto guardados a la vez con
 * la misma categoría nueva crean **dos categorías** y dejan la taxonomía
 * duplicada sin que nada falle.
 *
 * Con el índice, la segunda petición recibe una
 * `UniqueConstraintViolationException` — y propagarla sería igual de
 * equivocado, porque quien la provocó pidió «esta categoría» y la categoría
 * existe. Así que se vuelve a leer: gana quien insertó primero y el segundo se
 * lleva su fila. El bucle repite el par completo —leer, intentar— porque el
 * choque también puede ser del `slug`, y entonces lo que hay que rehacer no es
 * la lectura sino el slug, que en la segunda vuelta ya ve al ganador y se
 * desambigua con sufijo.
 *
 * ## La colación hace el resto
 *
 * `utf8mb4_unicode_ci` es *case-insensitive*, así que «Ropa» y «ropa» son la
 * misma categoría tanto para el índice como para el `where` de aquí — que es
 * exactamente lo que RB-8 quiere decir con «si el nombre ya existe, se
 * reutiliza». Lo que se guarda es la primera grafía que llegó, porque es la
 * que el negocio verá en su lista.
 */
final class TaxonomyByName
{
    /**
     * Cuántas veces se reintenta el par leer-crear antes de rendirse.
     *
     * Tres, y no uno: la primera vuelta puede chocar por nombre y la segunda
     * por slug, y las dos se resuelven leyendo o renumerando. Un tope existe
     * para que un fallo de unicidad **que no sea** una carrera —una columna
     * nueva con su propio índice, por ejemplo— termine en el error que es, en
     * lugar de en un bucle infinito.
     */
    private const ATTEMPTS = 3;

    public function __construct(private readonly CategoryOrder $order) {}

    /**
     * La categoría con ese nombre en la organización activa, creándola si hace
     * falta.
     *
     * @throws UniqueConstraintViolationException si el choque persiste y no es una carrera
     */
    public function category(string $name): Category
    {
        $name = self::clean($name);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $existing = Category::query()->where('name', $name)->first();

            if ($existing instanceof Category) {
                return $existing;
            }

            try {
                $category = Category::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from(
                        $name,
                        fn (string $slug): bool => Category::query()->where('slug', $slug)->exists(),
                        120,
                    ),
                    'position' => $this->order->next(),
                ]);

                // La nueva va al final y la lista queda contigua: crear desde
                // el formulario de producto tiene que dejar el orden manual
                // tan sano como crearla desde su pantalla.
                $this->order->apply();

                return $category->refresh();
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        // Inalcanzable: el bucle vuelve a leer o relanza. Está escrito porque
        // el tipo de retorno no admite `null` y porque un `while (true)` sin
        // tope sería peor.
        return Category::query()->where('name', $name)->sole();
    }

    /**
     * La etiqueta con ese nombre en la organización activa, creándola si hace
     * falta.
     *
     * Sin `position`: las etiquetas no se ordenan a mano, que es la diferencia
     * con las categorías y el motivo de que su tabla no tenga la columna.
     *
     * @throws UniqueConstraintViolationException si el choque persiste y no es una carrera
     */
    public function tag(string $name): Tag
    {
        $name = self::clean($name);

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $existing = Tag::query()->where('name', $name)->first();

            if ($existing instanceof Tag) {
                return $existing;
            }

            try {
                return Tag::query()->create([
                    'name' => $name,
                    'slug' => TaxonomySlug::from(
                        $name,
                        fn (string $slug): bool => Tag::query()->where('slug', $slug)->exists(),
                        80,
                    ),
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                if ($attempt === self::ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        return Tag::query()->where('name', $name)->sole();
    }

    /**
     * El nombre tal como se compara y se guarda.
     *
     * Los espacios de los extremos y los dobles espacios de en medio se
     * pierden: «Ropa  niño» y «Ropa niño» son la misma categoría para quien la
     * escribe, y dejarlas distintas produciría dos entradas que en la lista se
     * ven idénticas. Lo que **no** se toca son las mayúsculas ni los acentos:
     * de eso se encarga la colación, y normalizarlos aquí guardaría «ropa»
     * donde el negocio escribió «Ropa».
     */
    private static function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }
}
