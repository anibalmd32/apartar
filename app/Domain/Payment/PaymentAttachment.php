<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use InvalidArgumentException;

final readonly class PaymentAttachment
{
    /**
     * @throws InvalidArgumentException
     */
    public function __construct(
        public string $path,
        public string $hash,
        public string $mime,
        public int $size,
    ) {
        if ($path === '' || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || $mime === '' || $size < 1) {
            throw new InvalidArgumentException('Un adjunto de pago lleva ruta, huella SHA-256, tipo y tamaño.');
        }
    }

    /**
     * @return array{file_path: string, file_hash: string, file_mime: string, file_size: int}
     */
    public function toColumns(): array
    {
        return [
            'file_path' => $this->path,
            'file_hash' => $this->hash,
            'file_mime' => $this->mime,
            'file_size' => $this->size,
        ];
    }
}
