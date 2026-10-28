<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image as AiImage;
use Laravel\Ai\Files\LocalImage;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

final class CopywriterImages
{
    public const MAX_IMAGES = 4;

    public const DOWNSCALE_ABOVE_BYTES = 1_500_000;

    public const MAX_SIDE = 1280;

    private const STORED_CONVERSION = 'card';

    /**
     * @var list<string>
     */
    private array $temporary = [];

    /**
     * @param  list<UploadedFile>  $uploads
     * @return list<Base64Image|LocalImage>
     */
    public function prepare(array $uploads, ?Product $product): array
    {
        $images = [];

        foreach (array_slice($uploads, 0, self::MAX_IMAGES) as $upload) {
            $images[] = $this->fromUpload($upload);
        }

        if ($product instanceof Product) {
            foreach ($product->getMedia(Product::IMAGES) as $media) {
                if (count($images) >= self::MAX_IMAGES) {
                    break;
                }

                $stored = $this->fromMedia($media);

                if ($stored !== null) {
                    $images[] = $stored;
                }
            }
        }

        return $images;
    }

    public function release(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }

        $this->temporary = [];
    }

    private function fromUpload(UploadedFile $upload): Base64Image|LocalImage
    {
        if ((int) $upload->getSize() <= self::DOWNSCALE_ABOVE_BYTES) {
            return AiImage::fromUpload($upload, $upload->getMimeType());
        }

        $target = sys_get_temp_dir().'/'.uniqid('apartar-ai-', true).'.jpg';

        try {
            Image::useImageDriver((string) config('media-library.image_driver', 'gd'))
                ->loadFile($upload->getRealPath())
                ->fit(Fit::Max, self::MAX_SIDE, self::MAX_SIDE)
                ->quality(80)
                ->save($target);
        } catch (Throwable $exception) {
            report($exception);

            return AiImage::fromUpload($upload, $upload->getMimeType());
        }

        $this->temporary[] = $target;

        return AiImage::fromPath($target, 'image/jpeg');
    }

    private function fromMedia(Media $media): ?LocalImage
    {
        $converted = $media->hasGeneratedConversion(self::STORED_CONVERSION);
        $path = $converted ? $media->getPath(self::STORED_CONVERSION) : $media->getPath();

        if (! is_file($path)) {
            return null;
        }

        return AiImage::fromPath($path, $converted ? 'image/webp' : $media->mime_type);
    }
}
