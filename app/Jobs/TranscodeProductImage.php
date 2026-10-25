<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\RunsInTenantContext;
use App\Support\Media\TenantPathGenerator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Spatie\MediaLibrary\Conversions\FileManipulator;
use Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob;
use Throwable;

final class TranscodeProductImage extends PerformConversionsJob
{
    use RunsInTenantContext;

    public const REVIEW_PROPERTY = 'conversion_failed';

    /**
     * @throws RuntimeException
     */
    public function tenantOrganizationId(): int
    {
        $organizationId = $this->media->getCustomProperty(TenantPathGenerator::ORGANIZATION_PROPERTY);

        if (! is_numeric($organizationId)) {
            throw new RuntimeException(sprintf(
                'El media %s no declara su organización, así que este job no sabe en qué tenant trabajar. '
                .'Es el mismo dato que `TenantPathGenerator` necesita para componer su ruta.',
                (string) $this->media->getKey(),
            ));
        }

        return (int) $organizationId;
    }

    /**
     * @throws ModelNotFoundException
     */
    public function handle(FileManipulator $fileManipulator): bool
    {
        return (bool) $this->runInTenantContext(fn (): bool => parent::handle($fileManipulator));
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new RuntimeException('La conversión de una imagen falló sin excepción.'));

        $this->media->setCustomProperty(self::REVIEW_PROPERTY, true)->save();
    }
}
