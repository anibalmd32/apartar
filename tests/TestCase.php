<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesNeighbourOrganization;
use Tests\Concerns\FailsOnCounterDrift;
use Tests\Concerns\FakesPwnedPasswords;

/**
 * Caso base de toda la suite.
 *
 * `CreatesNeighbourOrganization` se aplica aquí y no test a test porque
 * `REQ-QAX-003` RB-10 lo pide en **todo** test de feature: el helper tiene que
 * estar a mano sin ceremonia, o acabará no usándose y los escenarios de un
 * solo tenant volverán por la puerta de atrás.
 *
 * Y desde `T-003b`, **ningún test sale a la red** (RB-9). Eso ya estaba
 * escrito y no se estaba cumpliendo; el porqué y el cómo están en
 * `FakesPwnedPasswords`.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesNeighbourOrganization, FailsOnCounterDrift, FakesPwnedPasswords;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * RB-9 · ningún test llega a la red real, y ahora se **impide** en vez
         * de pedirse.
         *
         * `preventStrayRequests()` convierte cualquier petición HTTP sin
         * falsear en un error que dice qué URL se intentó. La alternativa era
         * seguir confiando en que nadie escriba una, y la suite ya tenía dos
         * —al servicio de contraseñas filtradas— que llevaban meses saliendo a
         * Internet sin que nada lo dijera.
         *
         * Lo que esto cambia para quien escriba un test nuevo: si el código
         * bajo prueba habla con un tercero, hay que falsearlo. Eso es
         * exactamente lo que RB-9 pide, y ahora el fallo lo recuerda en el
         * sitio y en el momento.
         */
        Http::preventStrayRequests();

        $this->fakeUncompromisedPasswordService();

        // `T-045` · un `below_zero` del contador del plan es una deriva real;
        // el porqué está en `FailsOnCounterDrift`.
        $this->watchCounterDrift();
    }

    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        $this->assertNoCounterDrift();
    }
}
