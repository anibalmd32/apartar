<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\CreatesNeighbourOrganization;

/**
 * Caso base de toda la suite.
 *
 * `CreatesNeighbourOrganization` se aplica aquí y no test a test porque
 * `REQ-QAX-003` RB-10 lo pide en **todo** test de feature: el helper tiene que
 * estar a mano sin ceremonia, o acabará no usándose y los escenarios de un
 * solo tenant volverán por la puerta de atrás.
 */
abstract class TestCase extends BaseTestCase
{
    use CreatesNeighbourOrganization;
}
