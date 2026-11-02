<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use InvalidArgumentException;

enum SurplusDestinationType: string
{
    case TeamMember = 'team_member';
    case Tip = 'tip';
    case PettyCash = 'petty_cash';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::TeamMember => 'Una persona del equipo',
            self::Tip => 'Propina',
            self::PettyCash => 'Caja chica',
            self::Other => 'Otro',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::TeamMember => 'el equipo',
            self::Tip => 'Propina',
            self::PettyCash => 'Caja chica',
            self::Other => 'Otro',
        };
    }

    public function requiresNote(): bool
    {
        return $this === self::Other;
    }

    public function requiresUser(): bool
    {
        return $this === self::TeamMember;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertNote(string $type, ?string $note): void
    {
        if ($type === self::Other->value && ($note === null || trim($note) === '')) {
            throw new InvalidArgumentException('El destino «Otro» necesita una nota corta que diga qué se hizo con el sobrante.');
        }
    }
}
