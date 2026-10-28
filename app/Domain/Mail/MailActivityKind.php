<?php

declare(strict_types=1);

namespace App\Domain\Mail;

enum MailActivityKind: string
{
    case Sent = 'sent';
    case Event = 'event';
    case Inbound = 'inbound';
}
