<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class UnreadMessageNotification extends Notification
{
    public const EXCERPT_LENGTH = 100;

    public function __construct(
        public readonly string $businessName,
        public readonly string $excerpt,
        public readonly string $url,
    ) {}

    /**
     * @return list<class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->businessName)
            ->body($this->excerpt)
            ->data(['url' => $this->url])
            ->tag('chat');
    }
}
