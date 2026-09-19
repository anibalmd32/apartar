<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Models\Catalog;

/**
 * Si la organización activa tiene catálogos publicados (`REQ-ORG-006` SP-5).
 *
 * Existe por una regla concreta: **cambiar el slug con catálogos publicados da
 * `422`**, porque rompería los enlaces de la vitrina ya compartidos — y el
 * caso de uso de la vitrina es literalmente pegar esa URL en una bio de
 * Instagram. Un negocio que renombra su enlace y descubre a los tres días que
 * nadie llega no tiene forma de saber por qué.
 *
 * ## El relevo, y por qué estuvo abierto
 *
 * La regla se escribió en `T-014` con su forma definitiva, pero `catalogs` no
 * existía: la respuesta de entonces era que no había ninguno publicado, lo
 * cual era **literalmente cierto** y no un `TODO` disfrazado.
 *
 * Lo que sí era una bomba de relojería fue la primera versión de la guarda,
 * que comprobaba que la tabla **no** existiera y devolvía `false`. `T-024`
 * crea esa tabla ocho tareas antes de que `T-032` publique nada, y en esa
 * ventana `REQ-ORG-006` SP-5 habría quedado desactivado **sin un solo test en
 * rojo**: el síntoma no aparece hasta que un negocio renombra su slug y
 * descubre a los tres días que nadie llega a su vitrina. `T-016` invirtió la
 * guarda para que lanzara al llegar la tabla, y `T-024` —esta misma tarea—
 * cierra el relevo escribiendo la consulta de verdad.
 *
 * ## Va por el global scope, y esa es la mitad que importa
 *
 * `Catalog::query()` lleva el filtro del tenant puesto, así que **el catálogo
 * publicado de otra organización no cuenta**. Escribir aquí un
 * `DB::table('catalogs')->where('organization_id', …)` a mano daría el mismo
 * resultado hoy y sería exactamente la línea que `tenancy:audit` existe para
 * que nadie escriba: convierte el aislamiento en algo que hay que acordarse de
 * hacer.
 *
 * Sigue sin ser `final` y sigue teniendo su propio tipo —en vez de ser un
 * método de la acción que la usa— por lo mismo de siempre: es el punto por el
 * que `UpdateOrganizationActionTest` sustituye la respuesta para ejercitar el
 * `422` sin montar un catálogo entero.
 */
class PublishedCatalogs
{
    /**
     * Indica si queda algún catálogo publicado de la organización activa.
     *
     * Un catálogo **expirado sigue contando**: su `published_at` no es nulo y
     * su URL circuló igual, así que cambiar el slug del negocio rompería los
     * enlaces compartidos lo mismo. Lo que devuelve el catálogo a borrador es
     * despublicarlo (`REQ-CAT-022` RB-7), y eso sí vacía `published_at`.
     */
    public function exist(): bool
    {
        return Catalog::query()->whereNotNull('published_at')->exists();
    }
}
