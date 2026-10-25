<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Export\ExportStatus;
use App\Jobs\PurgeExpiredExports;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Queries\Platform\ExpiredExports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExportIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_purge_finds_every_organization_and_touches_only_each_ones(): void
    {
        Storage::fake(ExportJob::DISK);
        $ours = Organization::factory()->create();
        $theirs = $this->neighbourOrganization();

        foreach ([$ours, $theirs] as $organization) {
            $this->withinOrganization($organization, function (): void {
                $this->subscribeWithoutLimits();
                $export = ExportJob::factory()->completed()->create(['expires_at' => CarbonImmutable::now()->subDay()]);
                Storage::disk(ExportJob::DISK)->put((string) $export->file_path, 'xlsx');
            });
        }

        $this->withoutOrganization();
        $this->assertSame([(int) $ours->getKey(), (int) $theirs->getKey()], app(ExpiredExports::class)->organizationIds(CarbonImmutable::now()));

        $this->assertSame(2, app()->call([new PurgeExpiredExports, 'handle']));

        foreach ([$ours, $theirs] as $organization) {
            $this->assertSame([ExportStatus::Expired], $this->withinOrganization($organization, fn (): array => ExportJob::query()->pluck('status')->all()));
        }

        $this->assertSame([], Storage::disk(ExportJob::DISK)->allFiles());
    }
}
