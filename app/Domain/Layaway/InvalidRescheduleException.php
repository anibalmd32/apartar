<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

use RuntimeException;

/**
 * Una reprogramación que no se puede hacer (`REQ-LAY-011`), con la frase que
 * se le dice a quien la intentó.
 *
 * Tres motivos y una sola excepción: los tres terminan igual —`422` en el
 * campo de la fecha, sin tocar nada—, y la fuera de ventana lleva el rango
 * válido escrito, que es lo que SP-1 pide para no tener que adivinarlo.
 */
final class InvalidRescheduleException extends RuntimeException
{
    private function __construct(string $message, public readonly ?RescheduleWindow $window = null)
    {
        parent::__construct($message);
    }

    /**
     * RB-2, SP-3 · una cuota ya cubierta no se mueve.
     */
    public static function alreadyPaid(int $number): self
    {
        return new self(sprintf('La cuota %d ya está pagada y no se puede reprogramar.', $number));
    }

    /**
     * Un apartado que no está cobrándose —esperando la inicial, o ya cerrado—
     * no tiene un cronograma que reprogramar.
     */
    public static function layawayNotCollecting(LayawayStatus $status): self
    {
        return new self(sprintf('Un apartado %s no tiene cuotas que reprogramar.', mb_strtolower($status->label())));
    }

    /**
     * RB-3, SP-1, SP-2 · la fecha cae fuera de la ventana, que se dice entera.
     */
    public static function outsideWindow(int $number, RescheduleWindow $window): self
    {
        $message = $window->isEmpty()
            ? sprintf('La cuota %d no se puede mover: la siguiente vence antes de mañana.', $number)
            : sprintf('La nueva fecha de la cuota %d tiene que estar %s.', $number, $window->describe());

        return new self($message, $window);
    }
}
