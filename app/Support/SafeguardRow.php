<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Money\MoneyFormatter;
use App\Domain\Payment\ReceiptFile;
use App\Domain\Safeguard\ParallelEquivalent;
use App\Domain\Safeguard\SafeguardDestination;
use App\Models\Payment;
use App\Models\PaymentSafeguard;
use DateTimeZone;
use Illuminate\Support\Facades\URL;

final class SafeguardRow
{
    public const PROOF_TTL_MINUTES = 15;

    /**
     * @return array<string, mixed>
     */
    public static function of(Payment $payment, ParallelEquivalent $equivalent, ?PaymentSafeguard $record, DateTimeZone $timezone): array
    {
        $gap = $equivalent->gap();

        return [
            'equivalent' => $equivalent->equivalent === null ? null : MoneyFormatter::format($equivalent->equivalent),
            'gap' => $gap === null || $gap->cents === 0 ? null : MoneyFormatter::format($gap),
            'parallelRate' => $equivalent->parallelRate,
            'storeUrl' => route('payments.safeguard.store', $payment),
            'destroyUrl' => $record === null ? null : route('payments.safeguard.destroy', $payment),
            'record' => $record === null ? null : [
                'destination' => $record->destination->value,
                'destinationLabel' => $record->destination->label(),
                'reference' => $record->reference,
                'note' => $record->note,
                'proofUrl' => $record->proof_path === null
                    ? null
                    : URL::temporarySignedRoute('payments.safeguard.proof', now()->addMinutes(self::PROOF_TTL_MINUTES), ['payment' => $payment->getKey()]),
                'recordedAt' => $record->updated_at === null ? null : HumanDate::short($record->updated_at->setTimezone($timezone)),
            ],
        ];
    }

    /**
     * @return array{destinations: list<array{value: string, label: string}>, file: array{accept: string, maxMb: int}}
     */
    public static function options(): array
    {
        return [
            'destinations' => SafeguardDestination::options(),
            'file' => ['accept' => ReceiptFile::ACCEPT, 'maxMb' => ReceiptFile::maxSizeMb()],
        ];
    }
}
