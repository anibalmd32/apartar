<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\CatalogUnavailableException;
use App\Models\Catalog;
use Tests\TestCase;

/**
 * El catálogo que ya no admite apartados, dicho a quien lo estaba viendo
 * (`REQ-LAY-001` SP-3, SP-5).
 */
final class CatalogUnavailableExceptionTest extends TestCase
{
    public function test_it_is_a_422_with_one_sentence_for_every_reason(): void
    {
        $catalog = new Catalog;

        $exception = CatalogUnavailableException::for($catalog);

        $this->assertSame(422, CatalogUnavailableException::STATUS);
        $this->assertSame('Este catálogo ya no está disponible.', $exception->getMessage());
        $this->assertSame($catalog, $exception->catalog);
    }
}
