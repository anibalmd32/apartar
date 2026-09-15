<?php

declare(strict_types=1);

use App\Domain\Catalog\PublishedCatalogs;
use Illuminate\Support\Facades\Schema;

/**
 * El punto de extensión de SP-5 (`REQ-ORG-006`).
 *
 * `UpdateOrganizationAction` ya pregunta, ya bloquea y ya tiene su test —con
 * esta clase sustituida en el contenedor—. Lo que se comprueba aquí es la
 * otra mitad: que **la respuesta de hoy es honesta y no un `TODO` disfrazado**.
 *
 * `catalogs` llega con `T-030`; hasta entonces ninguna organización tiene
 * catálogos publicados, que es literalmente cierto. Cuando esa tabla exista, el
 * cuerpo de `exist()` pasa a ser la consulta del modelo y no cambia nada más.
 */
it('responde que no hay catálogos publicados mientras la tabla no exista', function (): void {
    expect(Schema::hasTable('catalogs'))->toBeFalse('`catalogs` ya existe: `PublishedCatalogs::exist()` tiene que consultarla (T-030).');

    expect((new PublishedCatalogs)->exist())->toBeFalse();
});

/**
 * Y se puede sustituir, que es lo que permite ejercitar el `422` de SP-5 antes
 * de que exista el modelo. Si la clase fuera `final`, la regla no se podría
 * probar hasta H2.
 */
it('se puede sustituir para ejercitar el bloqueo del enlace', function (): void {
    $withCatalogs = new class extends PublishedCatalogs
    {
        public function exist(): bool
        {
            return true;
        }
    };

    expect($withCatalogs->exist())->toBeTrue();
});
