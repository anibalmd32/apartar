<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\FeedCursor;
use App\Models\Organization;
use App\Models\Product;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class FeedCursorTest extends TestCase
{
    public function test_it_round_trips_the_key(): void
    {
        $item = [
            'product' => (new Product)->forceFill(['id' => 42]),
            'organization' => (new Organization)->forceFill(['id' => 7]),
            'enteredAt' => CarbonImmutable::parse('2026-10-20 10:00:00'),
        ];

        $cursor = FeedCursor::decode(FeedCursor::encode($item));

        $this->assertNotNull($cursor);
        $this->assertSame('2026-10-20 10:00:00', $cursor->enteredAt->format('Y-m-d H:i:s'));
        $this->assertSame(7, $cursor->organizationId);
        $this->assertSame(42, $cursor->productId);
        $this->assertSame(['2026-10-20 10:00:00', 7, 42], FeedCursor::keyOf($item));
    }

    public function test_an_unreadable_cursor_is_no_cursor(): void
    {
        foreach ([null, '', 'basura', base64_encode('{"a":1}'), base64_encode('["x", "7", 42]')] as $raw) {
            $this->assertNull(FeedCursor::decode($raw), (string) $raw);
        }
    }
}
