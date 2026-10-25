<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;

final class InvalidRescheduleException extends RuntimeException
{
    private function __construct(string $message, public readonly ?RescheduleWindow $window = null)
    {
        parent::__construct($message);
    }

    public static function alreadyPaid(int $number): self
    {
        return new self(sprintf('La cuota %d ya está pagada y no se puede reprogramar.', $number));
    }

    public static function layawayNotCollecting(LayawayStatus $status): self
    {
        return new self(sprintf('Un apartado %s no tiene cuotas que reprogramar.', mb_strtolower($status->label())));
    }

    public static function outsideWindow(int $number, RescheduleWindow $window): self
    {
        $message = $window->isEmpty()
            ? sprintf('La cuota %d no se puede mover: la siguiente vence antes de mañana.', $number)
            : sprintf('La nueva fecha de la cuota %d tiene que estar %s.', $number, $window->describe());

        return new self($message, $window);
    }
}
