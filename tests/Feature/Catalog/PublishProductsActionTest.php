<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\PublishProductsAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Subscription\UsageMetric;
use App\Jobs\TranscodeProductImage;
use App\Models\AuditEntry;
use App\Models\Category;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PublishProductsActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Bus::fake([TranscodeProductImage::class]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_publishes_every_complete_draft(): void
    {
        $this->subscribeWithoutLimits();
        $first = $this->publishable('Taladro');
        $second = $this->publishable('Sierra');

        $result = $this->publisher()->handle([(int) $first->getKey(), (int) $second->getKey()], $this->actor);

        $this->assertSame(['Sierra', 'Taladro'], $result->published);
        $this->assertSame([], $result->failed);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(ProductStatus::Published, $first->refresh()->status);
        $this->assertSame(ProductStatus::Published, $second->refresh()->status);
        $this->assertNotNull($first->published_at);
    }

    public function test_each_publication_goes_through_the_status_change_and_is_audited(): void
    {
        $this->subscribeWithoutLimits();
        $product = $this->publishable('Taladro');

        $this->publisher()->handle([(int) $product->getKey()], $this->actor);

        $this->assertSame(1, AuditEntry::query()
            ->where('event', AuditEvent::ProductStatusChanged->value)
            ->where('subject_id', $product->getKey())
            ->count());
    }

    public function test_incomplete_drafts_are_reported_while_the_rest_publish(): void
    {
        $this->subscribeWithoutLimits();
        $complete = $this->publishable('Taladro');
        $withoutPhoto = $this->publishable('Mochila', withImage: false);
        $withoutDescription = $this->publishable('Lentes');
        $withoutDescription->forceFill(['description' => null])->save();

        $result = $this->publisher()->handle(
            [(int) $withoutPhoto->getKey(), (int) $complete->getKey(), (int) $withoutDescription->getKey()],
            $this->actor,
        );

        $this->assertSame(['Taladro'], $result->published);
        $this->assertSame([
            ['name' => 'Lentes', 'reason' => 'le falta: la descripción'],
            ['name' => 'Mochila', 'reason' => 'le falta: al menos una foto'],
        ], $result->failed);
        $this->assertSame(ProductStatus::Published, $complete->refresh()->status);
        $this->assertSame(ProductStatus::Draft, $withoutPhoto->refresh()->status);
        $this->assertSame(ProductStatus::Draft, $withoutDescription->refresh()->status);
    }

    public function test_products_that_are_not_drafts_are_left_alone(): void
    {
        $this->subscribeWithoutLimits();
        $archived = Product::factory()->archived()->create();
        $published = Product::factory()->published()->create(['published_at' => now()->subDay()]);
        $stamp = $published->published_at?->toIso8601String();

        $result = $this->publisher()->handle([(int) $archived->getKey(), (int) $published->getKey()], $this->actor);

        $this->assertSame([], $result->published);
        $this->assertSame(2, $result->skipped);
        $this->assertSame(ProductStatus::Archived, $archived->refresh()->status);
        $this->assertSame($stamp, $published->refresh()->published_at?->toIso8601String());
    }

    public function test_ids_of_another_organization_are_ignored_and_untouched(): void
    {
        $this->subscribeWithoutLimits();
        $neighbour = $this->neighbourOrganization();
        $foreign = $this->withinOrganization($neighbour, function () use ($neighbour): Product {
            $this->subscribeWithoutLimits($neighbour);

            return $this->publishable('Ajeno', organization: $neighbour);
        });
        $this->actingAsOrganization($this->organization);
        $mine = $this->publishable('Taladro');

        $result = $this->publisher()->handle([(int) $foreign->getKey(), (int) $mine->getKey()], $this->actor);

        $this->assertSame(['Taladro'], $result->published);
        $this->assertSame(1, $result->skipped);
        $this->assertSame(
            ProductStatus::Draft,
            $this->withinOrganization($neighbour, fn (): ProductStatus => $foreign->refresh()->status),
        );
    }

    public function test_repeated_ids_count_once(): void
    {
        $this->subscribeWithoutLimits();
        $product = $this->publishable('Taladro');
        $id = (int) $product->getKey();

        $result = $this->publisher()->handle([$id, $id, $id], $this->actor);

        $this->assertSame(['Taladro'], $result->published);
        $this->assertSame(0, $result->skipped);
    }

    public function test_publishing_drafts_takes_no_extra_slot_even_at_the_plan_ceiling(): void
    {
        Subscription::factory()->forPlan(Plan::factory()->create(['max_products' => 2]))->create();
        $first = $this->publishable('Taladro');
        $second = $this->publishable('Sierra');
        $this->recalculateUsage();

        $result = $this->publisher()->handle([(int) $first->getKey(), (int) $second->getKey()], $this->actor);

        $this->assertCount(2, $result->published);
        $this->assertSame(2, (int) UsageCounter::query()->where('metric', UsageMetric::Products)->value('value'));
    }

    public function test_an_owner_of_another_organization_is_not_authorised(): void
    {
        $this->subscribeWithoutLimits();
        $product = $this->publishable('Taladro');

        $foreignOwner = $this->withinOrganization(
            $this->neighbourOrganization(),
            fn (): User => User::factory()->owner()->create(),
        );

        $this->actingAsOrganization($this->organization);

        try {
            $this->publisher()->handle([(int) $product->getKey()], $foreignOwner);
            $this->fail('Un dueño de otro negocio no puede publicar aquí.');
        } catch (AuthorizationException) {
            $this->assertSame(ProductStatus::Draft, $product->refresh()->status);
        }
    }

    private function publisher(): PublishProductsAction
    {
        $this->recalculateUsage();

        return app(PublishProductsAction::class);
    }

    private function publishable(string $name, bool $withImage = true, ?Organization $organization = null): Product
    {
        $product = Product::factory()
            ->inCategory(Category::factory()->create())
            ->create(['name' => $name, 'description' => 'Una descripción que vende.']);

        if ($withImage) {
            $product
                ->addMedia($this->image())
                ->withCustomProperties([
                    TenantPathGenerator::ORGANIZATION_PROPERTY => ($organization ?? $this->organization)->getKey(),
                ])
                ->toMediaCollection(Product::IMAGES);
        }

        return $product->refresh();
    }

    private function image(): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-foto.png';

        imagepng(imagecreatetruecolor(4, 4), $path);

        return new UploadedFile($path, 'foto.png', 'image/png', null, true);
    }
}
