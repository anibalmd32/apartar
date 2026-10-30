<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

final class DuplicateReceipts
{
    public static function inOrganization(?string $fileHash, ?string $bank, ?string $reference, ?string $exceptIdempotencyKey = null): ?DuplicateCriterion
    {
        $payments = Payment::query()->when($exceptIdempotencyKey !== null, fn (Builder $query) => $query->where(
            fn (Builder $query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $exceptIdempotencyKey),
        ));

        if ($fileHash !== null && (self::fileTaken(Receipt::query(), $fileHash) || self::fileTaken($payments, $fileHash))) {
            return DuplicateCriterion::FileHash;
        }

        return self::referenceTaken(Receipt::query(), $bank, $reference) || self::referenceTaken($payments, $bank, $reference)
            ? DuplicateCriterion::BankReference
            : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts
     */
    public static function criterionFor(Builder $receipts, string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        if (self::fileTaken($receipts, $fileHash)) {
            return DuplicateCriterion::FileHash;
        }

        return self::referenceTaken($receipts, $bank, $reference) ? DuplicateCriterion::BankReference : null;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts
     */
    public static function fileTaken(Builder $receipts, string $fileHash): bool
    {
        return (clone $receipts)->where('file_hash', $fileHash)->exists();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts
     */
    public static function referenceTaken(Builder $receipts, ?string $bank, ?string $reference): bool
    {
        return $reference !== null && (clone $receipts)->where('bank', $bank)->where('reference', $reference)->exists();
    }

    public static function fromViolation(UniqueConstraintViolationException $exception, string $table): ?DuplicateCriterion
    {
        return match (true) {
            str_contains($exception->getMessage(), $table.'_organization_id_file_hash_unique') => DuplicateCriterion::FileHash,
            str_contains($exception->getMessage(), $table.'_organization_id_bank_reference_unique') => DuplicateCriterion::BankReference,
            default => null,
        };
    }
}
