<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use RuntimeException;

final class SlugTakenException extends RuntimeException
{
    private function __construct(
        public readonly string $slug,
        public readonly string $suggestion,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function suggesting(string $slug, string $suggestion): self
    {
        return new self($slug, $suggestion, sprintf(
            'El enlace «%s» ya lo está usando otro negocio. «%s» está libre.',
            $slug,
            $suggestion,
        ));
    }

    public static function reserved(string $slug, string $suggestion): self
    {
        return new self($slug, $suggestion, sprintf(
            'El enlace «%s» está reservado por la plataforma. «%s» está libre.',
            $slug,
            $suggestion,
        ));
    }
}
