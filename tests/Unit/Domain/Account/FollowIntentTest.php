<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Account;

use App\Domain\Account\FollowIntent;
use App\Models\Catalog;
use Carbon\CarbonImmutable;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

final class FollowIntentTest extends TestCase
{
    private Store $session;

    private Catalog $catalog;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = new Store('test', new ArraySessionHandler(60));
        $this->catalog = (new Catalog)->forceFill(['id' => 7, 'slug' => 'ofertas']);
        $this->now = CarbonImmutable::parse('2026-10-21 10:00:00');
    }

    public function test_it_is_read_back_once(): void
    {
        FollowIntent::remember($this->session, $this->catalog, $this->now);

        $intent = FollowIntent::pull($this->session, $this->now->addMinutes(14));

        $this->assertNotNull($intent);
        $this->assertSame(7, $intent->catalogId);
        $this->assertSame('ofertas', $intent->catalogSlug);
        $this->assertTrue($intent->isFor($this->catalog));
        $this->assertNull(FollowIntent::pull($this->session, $this->now), 'Una intención se completa una vez.');
    }

    public function test_it_expires_after_fifteen_minutes_and_leaves_no_trace(): void
    {
        FollowIntent::remember($this->session, $this->catalog, $this->now);

        $this->assertNull(FollowIntent::pull($this->session, $this->now->addMinutes(FollowIntent::TTL_MINUTES)));
        $this->assertFalse($this->session->has(FollowIntent::SESSION_KEY));
    }

    public function test_another_catalog_is_not_the_intended_one(): void
    {
        $intent = FollowIntent::remember($this->session, $this->catalog, $this->now);

        $this->assertFalse($intent->isFor((new Catalog)->forceFill(['id' => 8, 'slug' => 'ofertas'])));
    }

    public function test_a_tampered_or_missing_value_is_no_intent(): void
    {
        $this->assertNull(FollowIntent::pull($this->session, $this->now));

        $this->session->put(FollowIntent::SESSION_KEY, ['catalog_id' => '7', 'catalog_slug' => 'ofertas', 'expires_at' => '2030-01-01']);

        $this->assertNull(FollowIntent::pull($this->session, $this->now));
    }
}
