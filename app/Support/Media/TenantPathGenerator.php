<?php

declare(strict_types=1);

namespace App\Support\Media;

use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

final class TenantPathGenerator implements PathGenerator
{
    public const ORGANIZATION_PROPERTY = 'organization_id';

    public function getPath(Media $media): string
    {
        return $this->basePath($media).'/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->basePath($media).'/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->basePath($media).'/responsive-images/';
    }

    /**
     * @throws RuntimeException
     */
    private function basePath(Media $media): string
    {
        $organizationId = $media->getCustomProperty(self::ORGANIZATION_PROPERTY);

        if (! is_numeric($organizationId)) {
            throw new RuntimeException(sprintf(
                'El media %s no declara su organización en custom_properties["%s"], así que no hay ruta que '
                .'componer. Quien lo añade tiene que declararla: sin ella el archivo caería en un directorio '
                .'compartido y el aislamiento físico entre negocios dejaría de existir.',
                (string) $media->getKey(),
                self::ORGANIZATION_PROPERTY,
            ));
        }

        return sprintf('organizations/%d/media/%s', (int) $organizationId, (string) $media->getKey());
    }
}
