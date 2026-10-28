<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Mail\OutgoingMailContext;
use App\Support\Mail\OutgoingMailRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationSending;
use Symfony\Component\Mime\Message;
use Throwable;

final class CaptureOutgoingMail
{
    public function __construct(
        private readonly OutgoingMailRecorder $recorder,
        private readonly OutgoingMailContext $context,
    ) {}

    public function handleNotificationSending(NotificationSending $event): void
    {
        $organizationId = $event->notifiable instanceof Model ? $event->notifiable->getAttribute('organization_id') : null;
        $notificationId = (string) $event->notification->id;

        if ($event->channel === 'mail' && $notificationId !== '' && is_numeric($organizationId)) {
            $this->context->remember($notificationId, (int) $organizationId);
        }
    }

    public function handleMessageSending(MessageSending $event): void
    {
        try {
            $this->recorder->prepare($event->message, $event->data);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function handleMessageSent(MessageSent $event): void
    {
        try {
            $original = $event->sent->getOriginalMessage();

            if ($original instanceof Message) {
                $this->recorder->markSent($original);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
