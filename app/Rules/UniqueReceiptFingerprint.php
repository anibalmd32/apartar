<?php

declare(strict_types=1);

namespace App\Rules;

use App\Domain\Payment\DuplicateReceiptException;
use App\Domain\Payment\DuplicateReceipts;
use App\Domain\Payment\ReceiptFile;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

final class UniqueReceiptFingerprint implements ValidationRule
{
    public function __construct(
        private readonly ?string $bank,
        private readonly ?string $reference,
        private readonly bool $recordedByBusiness = false,
        private readonly ?string $exceptIdempotencyKey = null,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $criterion = match (true) {
            $value instanceof UploadedFile => DuplicateReceipts::inOrganization(ReceiptFile::fingerprint($value), $this->bank, $this->reference, $this->exceptIdempotencyKey),
            is_string($value) => DuplicateReceipts::inOrganization(null, $this->bank, trim($value), $this->exceptIdempotencyKey),
            default => null,
        };

        if ($criterion !== null) {
            $fail($this->recordedByBusiness ? DuplicateReceiptException::recordedMessageFor($criterion) : DuplicateReceiptException::MESSAGE);
        }
    }
}
