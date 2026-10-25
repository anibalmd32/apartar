<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Actions\Export\ExportRequestData;
use App\Actions\Export\RequestExportAction;
use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Subscription\PlanFeatureUnavailableException;
use App\Domain\Tenancy\OrganizationRole;
use App\Jobs\GenerateExport;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RequestExportActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(ExportJob::DISK);
        $this->organization = Organization::factory()->create();
        $this->owner = $this->withinOrganization($this->organization, function (): User {
            $this->subscribeWithoutLimits();

            return User::factory()->owner()->create();
        });
    }

    public function test_it_queues_and_generates_nothing(): void
    {
        $export = $this->request(ExportType::Collections, ['preset' => 'due_today']);

        $this->assertSame(ExportStatus::Queued, $export->status);
        $this->assertSame($this->owner->getKey(), $export->requested_by);
        $this->assertNull($export->file_path);
        $this->assertSame([], Storage::disk(ExportJob::DISK)->allFiles());
        Queue::assertPushedOn('exports', GenerateExport::class, fn (GenerateExport $job): bool => $job->exportId === $export->getKey()
            && $job->organizationId === $this->organization->getKey()
            && $job->afterCommit === true);
    }

    public function test_only_the_keys_of_the_type_are_kept(): void
    {
        $export = $this->request(ExportType::Inventory, ['status' => 'published', 'preset' => 'due_today', 'q' => '', 'tag' => ['1'], 'category' => str_repeat('9', 200)]);

        $this->assertSame(['status' => 'published'], $export->filters);
    }

    public function test_one_in_progress_per_type_is_reused(): void
    {
        $first = $this->request(ExportType::Customers, []);

        $this->assertTrue($first->is($this->request(ExportType::Customers, ['q' => 'otra búsqueda'])));
        $this->assertFalse($first->is($this->request(ExportType::Inventory, [])));

        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($first->getKey())->update(['status' => ExportStatus::Completed]));
        $this->assertFalse($first->is($this->request(ExportType::Customers, [])));

        Queue::assertPushed(GenerateExport::class, 3);
    }

    public function test_a_stale_export_in_progress_does_not_block_its_type_forever(): void
    {
        $stuck = $this->request(ExportType::Customers, []);
        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($stuck->getKey())->update([
            'status' => ExportStatus::Processing,
            'created_at' => now()->subMinutes(ExportJob::STALE_MINUTES + 1),
        ]));

        $fresh = $this->request(ExportType::Customers, []);

        $this->assertFalse($fresh->is($stuck));
        $this->assertSame(ExportStatus::Failed, $this->withinOrganization($this->organization, fn (): ExportStatus => ExportJob::query()->findOrFail($stuck->getKey())->status));
    }

    public function test_without_the_feature_it_is_refused(): void
    {
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['plan_id' => Plan::factory()->create(['features' => PlanFeature::noneEnabled()])->getKey()]));

        $this->expectException(PlanFeatureUnavailableException::class);

        $this->request(ExportType::Collections, []);
    }

    public function test_who_sees_the_screen_exports_it(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => OrganizationRole::Member]));

        foreach (ExportType::cases() as $type) {
            $this->assertSame(ExportStatus::Queued, $this->request($type, [], $member)->status, $type->value);
        }

        $stranger = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->expectException(AuthorizationException::class);
        $this->withinOrganization($this->organization, fn () => app(RequestExportAction::class)->handle(ExportRequestData::of(ExportType::Inventory, []), $stranger));
    }

    /**
     * @param  array<mixed>  $filters
     */
    private function request(ExportType $type, array $filters, ?User $actor = null): ExportJob
    {
        return $this->withinOrganization($this->organization, fn (): ExportJob => app(RequestExportAction::class)->handle(ExportRequestData::of($type, $filters), $actor ?? $this->owner));
    }
}
