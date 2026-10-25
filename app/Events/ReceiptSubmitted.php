<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Receipt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

final class ReceiptSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public string $broadcastQueue = 'high';

    public function __construct(
        public readonly int $organizationId,
        public readonly int $receiptId,
        public readonly string $layawayCode,
        public readonly string $declaredAmount,
        public readonly string $declaredCurrency,
        public readonly string $submittedAt,
    ) {}

    public static function of(Receipt $receipt, string $layawayCode): self
    {
        return new self(
            organizationId: (int) $receipt->organization_id,
            receiptId: (int) $receipt->getKey(),
            layawayCode: $layawayCode,
            declaredAmount: (string) $receipt->declared_amount,
            declaredCurrency: $receipt->declared_currency->value,
            submittedAt: ($receipt->created_at ?? now())->toIso8601String(),
        );
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->organizationId))];
    }

    public static function channelFor(int $organizationId): string
    {
        return sprintf('organization.%d.receipts', $organizationId);
    }

    /**
     * @return array{id: int, layawayCode: string, declaredAmount: string, declaredCurrency: string, submittedAt: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->receiptId,
            'layawayCode' => $this->layawayCode,
            'declaredAmount' => $this->declaredAmount,
            'declaredCurrency' => $this->declaredCurrency,
            'submittedAt' => $this->submittedAt,
        ];
    }
}
