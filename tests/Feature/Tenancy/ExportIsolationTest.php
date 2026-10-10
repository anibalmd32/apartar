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

/**
 * Las exportaciones contra la organización vecina (`REQ-BOF-043` RB-11,
 * RB-12, `REQ-QAX-008` RB-2, `T-070`).
 *
 * Son del tenant: la lista, la descarga y la generación no ven las del
 * vecino (lo prueban `ExportControllerTest` y `GenerateExportTest`); aquí,
 * que la consulta de plataforma de la purga recorre a todas y la purga de
 * cada una toca solo lo suyo.
 */
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
