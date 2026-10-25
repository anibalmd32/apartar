<?php

declare(strict_types=1);

namespace App\Domain\Account;

enum AccountProvider: string
{
    case Google = 'google';
}
