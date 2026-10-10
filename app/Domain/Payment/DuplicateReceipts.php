<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * El bloqueo de comprobantes duplicados de `REQ-PAY-003` RB-2, **una sola
 * vez** para las dos tablas que lo necesitan (`T-069`): los comprobantes que
 * los clientes le suben al negocio (`receipts`) y los que el negocio le sube
 * a la plataforma por su suscripción (`subscription_receipts`,
 * `REQ-SUB-006` RB-2: «no se reimplementan»).
 *
 * Los dos criterios, en este orden: (a) el `sha256` del archivo tal como se
 * subió (`ReceiptFile::fingerprint()`) y (b) la pareja banco + referencia, que
 * sin referencia —el efectivo— no existe (RB-3). Sin filtro de estado: un
 * rechazado también cuenta (RB-5). Y **sin filtro de organización escrito**:
 * la consulta que se recibe ya va acotada por el global scope del tenant, que
 * es lo que hace que un duplicado sea «en esta organización» (RB-4).
 *
 * Las dos tablas declaran los mismos dos únicos
 * (`{tabla}_organization_id_file_hash_unique` y
 * `{tabla}_organization_id_bank_reference_unique`), y `fromViolation()`
 * traduce la carrera que los dispara (SP-5) al mismo criterio.
 */
final class DuplicateReceipts
{
    /**
     * Qué criterio coincide, o `null` si ninguno.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts  la tabla de comprobantes, acotada por el tenant
     */
    public static function criterionFor(Builder $receipts, string $fileHash, ?string $bank, ?string $reference): ?DuplicateCriterion
    {
        if (self::fileTaken($receipts, $fileHash)) {
            return DuplicateCriterion::FileHash;
        }

        return self::referenceTaken($receipts, $bank, $reference) ? DuplicateCriterion::BankReference : null;
    }

    /**
     * Criterio (a): ese archivo ya se reportó.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts
     */
    public static function fileTaken(Builder $receipts, string $fileHash): bool
    {
        return (clone $receipts)->where('file_hash', $fileHash)->exists();
    }

    /**
     * Criterio (b): esa pareja banco + referencia ya se reportó. Sin
     * referencia no hay criterio, y el único de la tabla tampoco ve una pareja
     * con `NULL`: los dos dicen lo mismo.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $receipts
     */
    public static function referenceTaken(Builder $receipts, ?string $bank, ?string $reference): bool
    {
        return $reference !== null && (clone $receipts)->where('bank', $bank)->where('reference', $reference)->exists();
    }

    /**
     * La carrera de SP-5: la otra subida ganó y el único de `$table` lo dice.
     * Cualquier otra violación de un único no es un duplicado: `null`.
     */
    public static function fromViolation(UniqueConstraintViolationException $exception, string $table): ?DuplicateCriterion
    {
        return match (true) {
            str_contains($exception->getMessage(), $table.'_organization_id_file_hash_unique') => DuplicateCriterion::FileHash,
            str_contains($exception->getMessage(), $table.'_organization_id_bank_reference_unique') => DuplicateCriterion::BankReference,
            default => null,
        };
    }
}
