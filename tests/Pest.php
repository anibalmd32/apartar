<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Caso base de los tests
|--------------------------------------------------------------------------
|
| Todo test de `Feature` arranca la aplicación y refresca la base de datos
| (`REQ-QAX-003` SP-5: ningún test pasa por casualidad al no aislarse la BD).
| `CreatesNeighbourOrganization` se aplica desde cada test que lo necesita,
| no de forma global, porque `T-007` es quien define su contenido.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
