<?php

declare(strict_types=1);

namespace App\Domain\Notification;

enum NotificationAudience: string
{
    case Customer = 'customer';

    case Owner = 'owner';

    case Subscriber = 'subscriber';
}
