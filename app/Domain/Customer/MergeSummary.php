<?php

declare(strict_types=1);

namespace App\Domain\Customer;

/**
 * Lo que mueve una fusión de fichas (`REQ-IDN-006` RB-2): antes de hacerla,
 * para que quien confirma vea qué pasa (el resumen de RB-8), y después, para
 * la auditoría.
 *
 * Los pagos no cuentan aparte: cuelgan de sus apartados y se mueven con ellos.
 * Los mensajes de la conversación y la telemetría de la ficha llegarán con
 * sus módulos (`T-080`, `T-084`), que añadirán aquí lo suyo.
 */
final readonly class MergeSummary
{
    public function __construct(
        public int $layaways,
        public int $receipts,
        public int $loginTokens,
        /** Si el vínculo con una cuenta de consumidor pasa a la superviviente (RB-4). */
        public bool $accountMoves,
    ) {}

    /**
     * @return array{layaways: int, receipts: int, login_tokens: int, account_moves: bool}
     */
    public function toArray(): array
    {
        return [
            'layaways' => $this->layaways,
            'receipts' => $this->receipts,
            'login_tokens' => $this->loginTokens,
            'account_moves' => $this->accountMoves,
        ];
    }
}
