<?php

declare(strict_types=1);

namespace App\Actions\Notification;

use InvalidArgumentException;

final readonly class ManualPushData
{
    public const TITLE_MAX = 60;

    public const BODY_MAX = 180;

    /**
     * @param  list<int>|null  $customerIds
     * @param  array<string, mixed>|null  $segment
     */
    public function __construct(
        public string $title,
        public string $body,
        public ?string $url,
        public ?array $customerIds,
        public ?array $segment,
    ) {
        if (($customerIds === null) === ($segment === null)) {
            throw new InvalidArgumentException('Un envío manual lleva fichas elegidas o un segmento, y solo uno.');
        }

        if (trim($title) === '' || mb_strlen($title) > self::TITLE_MAX || trim($body) === '' || mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('Título o cuerpo vacíos o demasiado largos.');
        }
    }
}
