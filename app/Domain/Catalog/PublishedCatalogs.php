<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Si la organización activa tiene catálogos publicados (`REQ-ORG-006` SP-5).
 *
 * Existe por una regla concreta: **cambiar el slug con catálogos publicados da
 * `422`**, porque rompería los enlaces de la vitrina ya compartidos — y el
 * caso de uso de la vitrina es literalmente pegar esa URL en una bio de
 * Instagram. Un negocio que renombra su enlace y descubre a los tres días que
 * nadie llega no tiene forma de saber por qué.
 *
 * **La regla está escrita con su forma definitiva; lo que falta es la tabla.**
 * `catalogs` llega con `T-024`, así que hoy la respuesta es que no hay ninguno
 * publicado — lo cual es literalmente cierto, no un `TODO` que aparenta
 * funcionar—. `UpdateOrganizationAction` ya pregunta, ya bloquea y ya tiene su
 * test: lo hace contra una implementación sustituida en el contenedor, que es
 * lo que permite ejercitar el `422` antes de que exista el modelo.
 *
 * ## Y por eso falla ruidosamente cuando llegue la tabla (`T-016`)
 *
 * Esta clase nació con la guarda al revés: comprobaba que `catalogs` **no**
 * existiera y respondía `false`. Parecía prudente y era una bomba de relojería
 * silenciosa. **`T-024` crea esa tabla ocho tareas antes de que `T-032` rellene
 * el cuerpo**, y desde ese commit la comprobación habría pasado, el cuerpo
 * habría seguido devolviendo `false`, y `REQ-ORG-006` SP-5 —el bloqueo del
 * enlace con catálogos publicados— habría quedado desactivado **sin un solo
 * test en rojo**. El síntoma no aparece hasta que un negocio renombra su slug,
 * y lo descubre a los tres días porque nadie llega a su vitrina: exactamente el
 * daño que la regla existía para evitar.
 *
 * Así que ahora, si la tabla existe y el cuerpo no se ha sustituido, **lanza**.
 * Es la convención de fallo cerrado que ya rige las declaraciones de superficie
 * y de suspensión de las rutas y el `match` sin `default` de
 * `LimitEnforcer::measure()`; `RecordedReceipts` (`T-015`) nació ya con esta
 * forma, copiada de aquí una vez corregida.
 *
 * **Quien lo rellena es `T-032`**, que es quien publica catálogos y por tanto
 * quien introduce el `published_at` que esta consulta lee. Si `T-024` llega
 * antes —y llega—, la suite se pone roja y dice qué escribir.
 */
class PublishedCatalogs
{
    /**
     * Indica si queda algún catálogo publicado de la organización activa.
     *
     * @throws LogicException si `catalogs` ya existe y nadie ha sustituido este cuerpo
     */
    public function exist(): bool
    {
        if (Schema::hasTable('catalogs')) {
            throw new LogicException(sprintf(
                'La tabla `catalogs` ya existe y %s::exist() sigue respondiendo que no hay ninguno publicado. '
                .'`T-032` es quien publica catálogos y quien tiene que sustituir este cuerpo por '
                .'`Catalog::query()->whereNotNull(\'published_at\')->exists()`, que va por el global scope del '
                .'tenant. Hasta que lo haga, `REQ-ORG-006` SP-5 estaría desactivado: cambiar el slug con la '
                .'vitrina ya circulando rompería los enlaces compartidos y ningún test se pondría en rojo.',
                self::class,
            ));
        }

        // Mientras la tabla no exista, ninguna organización tiene catálogos
        // publicados y esta es la única respuesta honesta.
        return false;
    }
}
