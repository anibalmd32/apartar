<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Domain\Export\ExportStatus;
use App\Domain\Subscription\UsageCalculator;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\TenantContext;
use App\Jobs\PurgeExpiredExports;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Models\UsageCounter;
use App\Queries\Platform\ExpiredExports;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La purga a los siete días (`REQ-BOF-043` RB-7, SP-4, `T-070`): borra el
 * archivo, deja la fila `expired` y **libera la cuota** de `storage_mb`.
 */
final class PurgeExpiredExportsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ExportJob::DISK);
        $this->organization = Organization::factory()->create();
        $this->withinOrganization($this->organization, fn () => $this->subscribeWithoutLimits());
        $this->recalculateUsage($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * A los siete días: sin archivo, `expired`, y el espacio deja de contar.
     * La que todavía no caduca se queda, y una segunda pasada no hace nada.
     */
    public function test_it_purges_at_seven_days_and_frees_the_storage(): void
    {
        $old = $this->export(5 * 1024 * 1024);
        $fresh = $this->export(1024);
        $this->recalculateUsage($this->organization);
        $this->assertSame(6, $this->storage());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(7)->addMinute());
        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($fresh->getKey())->update(['expires_at' => CarbonImmutable::now()->addDay()]));

        $this->assertSame(1, $this->purge());

        $this->assertSame(ExportStatus::Expired, $old->fresh()?->status);
        Storage::disk(ExportJob::DISK)->assertMissing((string) $old->file_path);
        Storage::disk(ExportJob::DISK)->assertExists((string) $fresh->file_path);
        $this->assertSame(ExportStatus::Completed, $fresh->fresh()?->status);
        $this->assertSame(1, $this->storage(), 'Solo queda la vigente, de 1 KB.');

        $this->assertSame(0, $this->purge(), 'Idempotente.');
    }

    /** Un archivo que ya no estaba no impide caducar la fila; y va en la programación. */
    public function test_a_missing_file_still_expires_and_it_is_scheduled(): void
    {
        $export = $this->export(1024);
        Storage::disk(ExportJob::DISK)->delete((string) $export->file_path);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(8));

        $this->assertSame(1, $this->purge());
        $this->assertSame(ExportStatus::Expired, $export->fresh()?->status);

        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'exports:purge-expired');
        $this->assertNotNull($event);
        $this->assertSame('0 3 * * *', $event->expression);
    }

    private function export(int $bytes): ExportJob
    {
        $export = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::factory()->completed($bytes)->create());
        Storage::disk(ExportJob::DISK)->put((string) $export->file_path, 'xlsx');

        return $export;
    }

    private function purge(): int
    {
        $this->withoutOrganization();

        return (new PurgeExpiredExports)->handle(app(ExpiredExports::class), app(TenantContext::class), app(UsageCalculator::class));
    }

    private function storage(): int
    {
        return (int) $this->withinOrganization($this->organization, fn (): mixed => UsageCounter::query()->where('metric', UsageMetric::StorageMb)->value('value'));
    }
}
