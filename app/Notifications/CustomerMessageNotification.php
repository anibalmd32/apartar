<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Notification\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class CustomerMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public readonly NotificationType $type,
        public readonly string $businessName,
        public readonly string $title,
        public readonly array $lines,
        public readonly string $url,
        public readonly string $action,
        public readonly bool $push,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        $allowed = $this->type->channels();

        if (in_array('mail', $allowed, true) && method_exists($notifiable, 'routeNotificationFor') && filled($notifiable->routeNotificationFor('mail', $this))) {
            $channels[] = 'mail';
        }

        if ($this->push && in_array('webpush', $allowed, true) && method_exists($notifiable, 'pushSubscriptions') && $notifiable->pushSubscriptions()->exists()) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->from((string) config('mail.from.address'), __('notifications.sender', ['negocio' => $this->businessName], 'es'))
            ->subject($this->title);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message->action($this->action, $this->url);
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->body(implode(' ', $this->lines))
            ->data(['url' => $this->url])
            ->tag($this->type->value);
    }
}
