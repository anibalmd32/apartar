<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\EventSanitizer;
use App\Domain\Telemetry\SanitizedEvent;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class EventSanitizerTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private Catalog $catalog;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
        $this->organization = Organization::factory()->create();
        $this->catalog = $this->withinOrganization($this->organization, fn (): Catalog => Catalog::factory()->published()->create());
        $this->withoutOrganization();
    }

    public function test_an_unknown_name_is_discarded(): void
    {
        $this->assertNull($this->sanitize(['name' => 'page_scrolled', 'catalogSlug' => $this->catalog->slug]));
        $this->assertNull($this->sanitize(['name' => ['catalog_viewed']]));
    }

    public function test_occurred_at_must_fall_inside_the_window(): void
    {
        $this->assertNull($this->sanitize($this->viewed(['occurredAt' => '2026-10-21T12:05:00Z'])));
        $this->assertNull($this->sanitize($this->viewed(['occurredAt' => '2026-10-20T11:59:00Z'])));
        $this->assertNull($this->sanitize($this->viewed(['occurredAt' => 'ayer'])));

        $event = $this->sanitize($this->viewed(['occurredAt' => '2026-10-21T07:30:00-04:00']));

        $this->assertSame('2026-10-21 11:30:00', $event?->occurredAt->format('Y-m-d H:i:s'));
    }

    public function test_the_organization_comes_from_the_slug_and_never_from_the_body(): void
    {
        $other = Organization::factory()->create();

        $event = $this->sanitize($this->viewed(['organization_id' => $other->getKey(), 'organizationId' => $other->getKey()]));

        $this->assertNotNull($event);
        $this->assertSame((int) $this->organization->getKey(), $event->organizationId);
        $this->assertSame((int) $this->catalog->getKey(), $event->catalogId);
        $this->assertSame('catalog', $event->subjectType);
        $this->assertSame((int) $this->catalog->getKey(), $event->subjectId);
    }

    public function test_an_unknown_draft_or_expired_catalog_attributes_nothing(): void
    {
        [$draft, $expired] = $this->withinOrganization($this->organization, fn (): array => [
            Catalog::factory()->create(),
            Catalog::factory()->expired()->create(),
        ]);

        foreach (['no-existe', $draft->slug, $expired->slug, null] as $slug) {
            $this->assertNull($this->sanitize(['name' => 'catalog_viewed', 'catalogSlug' => $slug]), (string) $slug);
        }

        $feed = $this->sanitize(['name' => 'feed_viewed', 'payload' => ['items' => 4]]);

        $this->assertNotNull($feed);
        $this->assertNull($feed->organizationId);
        $this->assertSame(['items' => 4], $feed->payload);
    }

    public function test_the_payload_keeps_only_declared_keys_of_the_declared_type(): void
    {
        $event = $this->sanitize([
            'name' => 'product_list_filtered',
            'catalogSlug' => $this->catalog->slug,
            'payload' => ['category' => 'taladros', 'tags' => ['oferta', 3], 'sort' => str_repeat('x', 201), 'results' => '7', 'email' => 'a@b.test'],
        ]);

        $this->assertSame(['category' => 'taladros'], $event?->payload);
    }

    public function test_an_oversized_payload_is_truncated_with_a_mark(): void
    {
        $tags = array_fill(0, 60, str_repeat('t', 100));

        $event = $this->sanitize(['name' => 'product_list_filtered', 'catalogSlug' => $this->catalog->slug, 'payload' => ['tags' => $tags]]);

        $this->assertSame(['truncated' => true], $event?->payload);
    }

    public function test_the_product_subject_is_resolved_inside_the_organization(): void
    {
        $product = $this->withinOrganization($this->organization, fn (): Product => Product::factory()->published()->create(['slug' => 'taladro']));
        $neighbour = Organization::factory()->create();
        $this->withinOrganization($neighbour, fn (): Product => Product::factory()->published()->create(['slug' => 'solo-del-vecino']));

        $own = $this->sanitize(['name' => 'product_viewed', 'catalogSlug' => $this->catalog->slug, 'subjectSlug' => 'taladro', 'subjectId' => 999]);
        $foreign = $this->sanitize(['name' => 'product_viewed', 'catalogSlug' => $this->catalog->slug, 'subjectSlug' => 'solo-del-vecino']);

        $this->assertNotNull($own);
        $this->assertSame((int) $product->getKey(), $own->subjectId);
        $this->assertSame('product', $own->subjectType);
        $this->assertNotNull($foreign);
        $this->assertNull($foreign->subjectId);
        $this->assertNull($foreign->subjectType);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function sanitize(array $raw): ?SanitizedEvent
    {
        return app(EventSanitizer::class)->sanitize($raw, $this->now);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function viewed(array $extra = []): array
    {
        return ['name' => 'catalog_viewed', 'catalogSlug' => $this->catalog->slug, ...$extra];
    }
}
