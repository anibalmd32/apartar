<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DownPaymentWindow
{
    public const MAX_HOURS = 2160;

    public const MAX_DAYS = 90;

    private function __construct(
        public ?int $hours,
        public bool $open,
    ) {}

    public static function organizationDefault(): self
    {
        return new self(null, false);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function hours(int $hours): self
    {
        if ($hours < 1 || $hours > self::MAX_HOURS) {
            throw new InvalidArgumentException(sprintf(
                'El plazo para pagar la inicial va de 1 a %d horas, y se pidieron %d.',
                self::MAX_HOURS,
                $hours,
            ));
        }

        return new self($hours, false);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function days(int $days): self
    {
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf(
                'El plazo para pagar la inicial va de 1 a %d días, y se pidieron %d.',
                self::MAX_DAYS,
                $days,
            ));
        }

        return new self($days * 24, false);
    }

    public static function withoutExpiry(): self
    {
        return new self(null, true);
    }

    public function isOrganizationDefault(): bool
    {
        return $this->hours === null && ! $this->open;
    }

    public function expiresAt(CarbonImmutable $now, int $organizationHours): ?CarbonImmutable
    {
        if ($this->open) {
            return null;
        }

        return $now->addHours($this->hours ?? $organizationHours);
    }

    public function describe(int $organizationHours): string
    {
        return match (true) {
            $this->open => 'sin vencimiento',
            $this->hours === null => sprintf('plazo del negocio (%d h)', $organizationHours),
            $this->hours % 24 === 0 => sprintf('%d %s', intdiv($this->hours, 24), $this->hours === 24 ? 'día' : 'días'),
            default => sprintf('%d %s', $this->hours, $this->hours === 1 ? 'hora' : 'horas'),
        };
    }
}
