<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Tenancy\TenantContext;
use App\Models\ExportJob;
use Database\Factories\Concerns\CreatesWithinTenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ExportJob>
 */
class ExportJobFactory extends Factory
{
    use CreatesWithinTenant;

    /** @var class-string<ExportJob> */
    protected $model = ExportJob::class;

    /**
     * @return array<model-property<ExportJob>, mixed>
     */
    public function definition(): array
    {
        return [
            'requested_by' => null,
            'type' => ExportType::Collections,
            'status' => ExportStatus::Queued,
            'filters' => [],
        ];
    }

    public function completed(int $bytes = 2_048): static
    {
        return $this->state(fn (): array => [
            'status' => ExportStatus::Completed,
            'file_path' => sprintf('%d/%s.xlsx', (int) app(TenantContext::class)->organization()->getKey(), Str::uuid()->toString()),
            'file_size' => $bytes,
            'row_count' => 0,
            'started_at' => now(),
            'completed_at' => now(),
            'expires_at' => now()->addDays(ExportJob::RETENTION_DAYS),
        ]);
    }
}
