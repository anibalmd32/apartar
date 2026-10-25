<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\FeedFilters;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class FeedFiltersTest extends TestCase
{
    public function test_it_reads_the_catalog_and_the_text(): void
    {
        $filters = FeedFilters::fromRequest(Request::create('/mi-cuenta/novedades', 'GET', ['catalogo' => 'ofertas', 'q' => 'taladro', 'categoria' => 'motores']));

        $this->assertSame(['catalog' => 'ofertas', 'q' => 'taladro'], $filters->toArray());
    }

    public function test_empty_values_are_no_filter(): void
    {
        $filters = FeedFilters::fromRequest(Request::create('/mi-cuenta/novedades', 'GET', ['catalogo' => '', 'q' => '   ']));

        $this->assertSame(['catalog' => null, 'q' => null], $filters->toArray());
    }
}
