<?php

declare(strict_types=1);

use App\Domain\Catalog\PublishedCatalogs;
use Illuminate\Support\Facades\Schema;

/**
 * El punto de extensión de SP-5 (`REQ-ORG-006`).
 *
 * `UpdateOrganizationAction` ya pregunta, ya bloquea y ya tiene su test —con
 * esta clase sustituida en el contenedor—. Lo que se comprueba aquí es la
 * otra mitad: que **la respuesta de hoy es honesta y no un `TODO` disfrazado**,
 * y que **deja de responder el día que llegue la tabla**.
 *
 * `catalogs` llega con `T-024`; hasta entonces ninguna organización tiene
 * catálogos publicados, que es literalmente cierto. Cuando esa tabla exista, el
 * cuerpo de `exist()` pasa a ser la consulta del modelo y no cambia nada más.
 */
it('responde que no hay catálogos publicados mientras la tabla no exista', function (): void {
    expect(Schema::hasTable('catalogs'))->toBeFalse('`catalogs` ya existe: `PublishedCatalogs::exist()` tiene que consultarla (T-032).');

    expect((new PublishedCatalogs)->exist())->toBeFalse();
});

/**
 * **Y falla ruidosamente cuando la tabla llegue** (`T-016`, cierre de H0).
 *
 * Es la deuda que esta tarea salda. La guarda estaba escrita al revés
 * —comprobaba que `catalogs` no existiera y devolvía `false`—, y `T-024` crea
 * esa tabla **ocho tareas antes** de que `T-032` rellene el cuerpo: en esa
 * ventana SP-5 quedaba desactivado sin un solo test en rojo, y el síntoma no
 * habría aparecido hasta que un negocio perdiera su enlace.
 *
 * Se finge que la tabla existe en vez de crearla: un `CREATE TABLE` a mitad de
 * un test provoca en MySQL un *commit* implícito que se lleva por delante el
 * aislamiento de `RefreshDatabase`.
 */
it('lanza si la tabla existe y nadie ha rellenado el cuerpo', function (): void {
    Schema::shouldReceive('hasTable')->with('catalogs')->andReturnTrue();

    expect(fn (): bool => (new PublishedCatalogs)->exist())
        ->toThrow(LogicException::class, 'T-032');
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
