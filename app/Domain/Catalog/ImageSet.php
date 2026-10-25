<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Jobs\TranscodeProductImage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final readonly class ImageSet
{
    public const ALT_PROPERTY = 'alt';

    /**
     * @var array<string, int>
     */
    public const CONVERSIONS = [
        'thumb' => 400,
        'card' => 800,
        'zoom' => 1600,
    ];

    public const QUALITY = 82;

    public function __construct(
        public string $thumb,
        public string $card,
        public string $zoom,
        public string $alt,
    ) {}

    public static function of(Media $media): self
    {
        return new self(
            thumb: self::urlFor($media, 'thumb'),
            card: self::urlFor($media, 'card'),
            zoom: self::urlFor($media, 'zoom'),
            alt: self::altOf($media),
        );
    }

    public static function defaultAlt(string $productName, int $number): string
    {
        return sprintf('%s — imagen %d', $productName, $number);
    }

    public static function needsReview(Media $media): bool
    {
        return (bool) $media->getCustomProperty(TranscodeProductImage::REVIEW_PROPERTY, false);
    }

    /**
     * @return array{thumb: string, card: string, zoom: string, alt: string}
     */
    public function toArray(): array
    {
        return [
            'thumb' => $this->thumb,
            'card' => $this->card,
            'zoom' => $this->zoom,
            'alt' => $this->alt,
        ];
    }

    private static function urlFor(Media $media, string $conversion): string
    {
        return $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : $media->getUrl();
    }

    private static function altOf(Media $media): string
    {
        $alt = $media->getCustomProperty(self::ALT_PROPERTY);

        return is_string($alt) && trim($alt) !== '' ? trim($alt) : $media->name;
    }
}
