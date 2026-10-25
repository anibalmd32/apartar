<?php

declare(strict_types=1);

namespace App\Rules;

use App\Actions\Payment\SubmitReceiptAction;
use App\Domain\Payment\DuplicateCriterion;
use App\Domain\Payment\DuplicateReceiptException;
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
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $action = app(SubmitReceiptAction::class);

        $criterion = match (true) {
            $value instanceof UploadedFile => $action->duplicateOf(ReceiptFile::fingerprint($value), $this->bank, $this->reference),
            is_string($value) => $action->referenceTaken($this->bank, trim($value)) ? DuplicateCriterion::BankReference : null,
            default => null,
        };

        if ($criterion !== null) {
            $fail(DuplicateReceiptException::MESSAGE);
        }
    }
}
