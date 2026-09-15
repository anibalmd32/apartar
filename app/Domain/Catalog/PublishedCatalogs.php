<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use Illuminate\Support\Facades\Schema;

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
 * `catalogs` llega con H2 (`T-030`), así que hoy la respuesta es que no hay
 * ninguno publicado — lo cual es literalmente cierto, no un `TODO` que
 * aparenta funcionar—. `UpdateOrganizationAction` ya pregunta, ya bloquea y ya
 * tiene su test: lo hace contra una implementación sustituida en el
 * contenedor, que es lo que permite ejercitar el `422` antes de que exista el
 * modelo.
 *
 * Cuando `T-030` cree `Catalog`, el cuerpo de `exist()` pasa a ser la consulta
 * comentada abajo y no cambia nada más: ni la acción, ni la ruta, ni el
 * mensaje.
 */
class PublishedCatalogs
{
    /**
     * Indica si queda algún catálogo publicado de la organización activa.
     *
     * La comprobación de la tabla no es una precaución genérica: es lo que
     * distingue «todavía no existe el concepto» de «existe y no hay ninguno»,
     * y evita que esta clase reviente en el momento exacto en que `T-030`
     * añada el modelo pero antes de que alguien migre.
     */
    public function exist(): bool
    {
        if (! Schema::hasTable('catalogs')) {
            return false;
        }

        // `T-030` sustituye esto por la consulta del modelo, que va por el
        // global scope del tenant:
        //
        //     return Catalog::query()->whereNotNull('published_at')->exists();
        //
        // Hasta entonces la tabla no existe y la respuesta de arriba es la
        // única honesta.
        return false;
    }
}
