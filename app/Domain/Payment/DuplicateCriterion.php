<?php

declare(strict_types=1);

namespace App\Domain\Payment;

enum DuplicateCriterion: string
{
    case FileHash = 'file_hash';

    case BankReference = 'bank_reference';
}
