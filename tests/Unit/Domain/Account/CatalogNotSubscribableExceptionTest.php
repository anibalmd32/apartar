<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\CatalogNotSubscribableException;
use App\Domain\Catalog\CatalogVisibility;
use App\Models\Catalog;
use PHPUnit\Framework\TestCase;

final class CatalogNotSubscribableExceptionTest extends TestCase
{
    public function test_it_carries_the_visibility_and_a_message_for_the_person(): void
    {
        $exception = CatalogNotSubscribableException::for((new Catalog)->forceFill(['published_at' => null]));

        $this->assertSame(422, CatalogNotSubscribableException::STATUS);
        $this->assertSame(CatalogVisibility::Draft, $exception->visibility);
        $this->assertSame(CatalogNotSubscribableException::MESSAGE, $exception->getMessage());
    }
}
