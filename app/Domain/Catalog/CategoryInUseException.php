<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Category;
use RuntimeException;

/**
 * La categoría tiene productos dentro, así que la operación pedida no se puede
 * aceptar (`REQ-BOF-023` SP-1 y SP-3).
 *
 * ## Un solo hecho, dos operaciones que bloquea
 *
 * Bloquea **borrar** —la FK de `products.category_id` es `restrict`, y un
 * producto sin categoría queda sin sitio (RB-4)— y bloquea **cambiar el
 * `slug`** cuando además hay productos publicados: el slug viaja en la query
 * string de los filtros de un catálogo, y cambiarlo rompe en silencio los
 * enlaces que el negocio ya repartió (RB-3, RB-6). Las dos cosas las impide la
 * misma frase —«esta categoría tiene productos»— así que son dos
 * constructores de una excepción, no dos clases: separarlas obligaría a
 * duplicar el conteo, que es lo único que el mensaje necesita de verdad.
 *
 * Lo que sí distinguen es **dónde va el error**, y por eso viaja `$field`: el
 * del borrado pertenece a la categoría entera y el del slug a su campo, que es
 * donde la persona lo puede corregir. Es la misma traducción que hace
 * `SettingsController` con `SlugLockedException`.
 *
 * ## Es una excepción y no un booleano
 *
 * Por lo mismo que `PaymentAccountInUseException`: **un valor de retorno se
 * puede ignorar sin escribir nada**. Un llamante que no mirara un `false`
 * enseñaría «categoría eliminada» sobre una categoría que sigue ahí, y ni
 * PHPStan ni un test dirían nada. Aquí, olvidarlo no es posible.
 *
 * `422`: la petición está bien formada y quien la hace tiene permiso. Lo que
 * no se puede es aceptarla — y la salida existe y se nombra en el mensaje:
 * reasignar los productos primero.
 */
final class CategoryInUseException extends RuntimeException
{
    /**
     * Código HTTP con el que se responde (SP-1, SP-3).
     */
    public const STATUS = 422;

    private function __construct(
        public readonly Category $category,
        public readonly int $productsCount,
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * SP-1 · se pidió borrarla y tiene productos.
     *
     * El conteo va en el mensaje porque es lo que convierte un «no puedes» en
     * una tarea concreta: reasignar tres productos es un minuto, y sin la
     * cifra quien lo lee no sabe si le espera eso o media tarde.
     */
    public static function cannotBeDeleted(Category $category, int $products): self
    {
        return new self($category, $products, 'category', sprintf(
            'La categoría «%s» tiene %s asociado%s, así que no se puede eliminar. Reasígnalos a otra categoría '
            .'y vuelve a intentarlo.',
            $category->name,
            $products === 1 ? '1 producto' : $products.' productos',
            $products === 1 ? '' : 's',
        ));
    }

    /**
     * SP-3 · se pidió cambiar su `slug` y tiene productos publicados.
     *
     * Renombrarla sigue estando permitido (RB-6): lo que se cierra es el
     * enlace, no el nombre. Esa distinción está en el mensaje a propósito y
     * con la instrucción al lado, porque el guardado es todo o nada: quien
     * mandó las dos cosas a la vez no ha guardado ninguna, y necesita saber
     * que reintentar sin tocar el enlace **sí** funciona.
     */
    public static function slugIsLocked(Category $category, int $published): self
    {
        return new self($category, $published, 'slug', sprintf(
            'El enlace de «%s» ya no se puede cambiar: tiene %s publicado%s y los filtros que compartiste '
            .'por URL dejarían de funcionar. El nombre sí: vuelve a guardar dejando el enlace como estaba.',
            $category->name,
            $published === 1 ? '1 producto' : $published.' productos',
            $published === 1 ? '' : 's',
        ));
    }
}
