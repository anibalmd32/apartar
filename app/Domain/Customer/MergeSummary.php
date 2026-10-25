<?php

declare(strict_types=1);

namespace App\Domain\Customer;

final readonly class MergeSummary
{
    public function __construct(
        public int $layaways,
        public int $receipts,
        public int $loginTokens,
        public bool $accountMoves,
        public int $messages = 0,
        public int $telemetryEvents = 0,
    ) {}

    /**
     * @return array{layaways: int, receipts: int, login_tokens: int, account_moves: bool, messages: int, telemetry_events: int}
     */
    public function toArray(): array
    {
        return [
            'layaways' => $this->layaways,
            'receipts' => $this->receipts,
            'login_tokens' => $this->loginTokens,
            'account_moves' => $this->accountMoves,
            'messages' => $this->messages,
            'telemetry_events' => $this->telemetryEvents,
        ];
    }
}
