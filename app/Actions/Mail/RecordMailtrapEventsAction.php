<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Domain\Mail\MailActivityKind;
use App\Domain\Mail\MailEventType;
use App\Events\MailActivity;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use App\Support\Mail\OutgoingMailRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecordMailtrapEventsAction
{
    /**
     * @param  list<array<string, mixed>>  $events
     */
    public function handle(array $events): int
    {
        $recorded = 0;
        $mails = [];

        foreach ($events as $event) {
            $recorded += $this->record($event, $mails) ? 1 : 0;
        }

        if ($recorded > 0) {
            MailActivity::announce(MailActivityKind::Event, $mails);
        }

        return $recorded;
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  list<string>  $mails
     */
    private function record(array $event, array &$mails): bool
    {
        $type = MailEventType::fromMailtrap($event['event'] ?? null);

        if ($type === null) {
            return false;
        }

        $eventId = self::text($event['event_id'] ?? null, 100) ?? 'h:'.sha1((string) json_encode($event));

        if (SentMailEvent::query()->where('event_id', $eventId)->exists()) {
            return false;
        }

        try {
            return DB::transaction(function () use ($event, $eventId, $type, &$mails): bool {
                $messageId = self::text($event['message_id'] ?? null, 100) ?? '';
                $mail = $this->correlate($event, $messageId);
                $occurredAt = self::occurredAt($event['timestamp'] ?? null);

                SentMailEvent::query()->create([
                    'event_id' => $eventId,
                    'sent_mail_id' => $mail?->getKey(),
                    'mailtrap_message_id' => $messageId,
                    'type' => $type,
                    'email' => self::text($event['email'] ?? null, 255) ?? '',
                    'occurred_at' => $occurredAt,
                    'url' => self::text($event['url'] ?? null, 2048),
                    'reason' => self::text($event['reason'] ?? $event['response'] ?? null, 5000),
                    'response_code' => is_numeric($event['response_code'] ?? null) ? min(65535, max(0, (int) $event['response_code'])) : null,
                    'bounce_category' => self::text($event['bounce_category'] ?? null, 100),
                    'ip' => self::text($event['ip'] ?? null, 45),
                    'user_agent' => self::text($event['user_agent'] ?? null, 500),
                    'payload' => $event,
                    'created_at' => now(),
                ]);

                if ($mail !== null) {
                    $this->advance($mail, $type, $messageId, $occurredAt);
                    $mails[] = $mail->uuid;
                }

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function correlate(array $event, string $messageId): ?SentMail
    {
        $variables = $event['custom_variables'] ?? null;
        $uuid = is_array($variables) ? ($variables[OutgoingMailRecorder::VARIABLE] ?? null) : null;

        if (is_string($uuid) && Str::isUuid($uuid)) {
            $mail = SentMail::query()->where('uuid', strtolower($uuid))->lockForUpdate()->first();

            if ($mail !== null) {
                return $mail;
            }
        }

        if ($messageId === '') {
            return null;
        }

        $known = SentMailEvent::query()->where('mailtrap_message_id', $messageId)->whereNotNull('sent_mail_id')->value('sent_mail_id');

        return SentMail::query()
            ->where(fn ($query) => $query->where('mailtrap_message_id', $messageId)->when($known !== null, fn ($inner) => $inner->orWhere('id', $known)))
            ->lockForUpdate()
            ->first();
    }

    private function advance(SentMail $mail, MailEventType $type, string $messageId, CarbonImmutable $occurredAt): void
    {
        $mail->status = $mail->status->advancedBy($type->status());
        $mail->mailtrap_message_id ??= $messageId === '' ? null : $messageId;
        $mail->last_event_at = $mail->last_event_at === null || $occurredAt->greaterThan($mail->last_event_at) ? $occurredAt : $mail->last_event_at;

        match ($type) {
            MailEventType::Delivery => $mail->forceFill(['delivered_at' => self::earliest($mail->delivered_at, $occurredAt)]),
            MailEventType::Open => $mail->forceFill(['first_opened_at' => self::earliest($mail->first_opened_at, $occurredAt), 'opens_count' => $mail->opens_count + 1]),
            MailEventType::Click => $mail->forceFill(['first_clicked_at' => self::earliest($mail->first_clicked_at, $occurredAt), 'clicks_count' => $mail->clicks_count + 1]),
            default => null,
        };

        $mail->save();
    }

    private static function earliest(?\DateTimeInterface $current, CarbonImmutable $candidate): \DateTimeInterface
    {
        return $current === null || $candidate->lessThan($current) ? $candidate : $current;
    }

    private static function occurredAt(mixed $timestamp): CarbonImmutable
    {
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? CarbonImmutable::createFromTimestamp((int) $timestamp, config('app.timezone'))
            : CarbonImmutable::now();
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;
    }
}
