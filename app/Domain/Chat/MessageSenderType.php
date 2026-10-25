<?php

declare(strict_types=1);

namespace App\Domain\Chat;

enum MessageSenderType: string
{
    case Customer = 'customer';

    case Team = 'team';
}
