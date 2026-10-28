<?php

declare(strict_types=1);

namespace App\Actions\Mail;

use App\Domain\Mail\MailActivityKind;
use App\Domain\Mail\MailAddress;
use App\Domain\Mail\MailBody;
use App\Events\MailActivity;
use App\Models\InboundMail;
use App\Models\InboundMailAttachment;
use App\Support\Mail\MailThreads;
use App\Support\Mail\MailtrapInboundClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ImportInboundMessageAction
{
    public function __construct(
        private readonly MailtrapInboundClient $client,
        private readonly MailThreads $threads,
    ) {}

    public function handle(int $inboxId, string $messageId): ?InboundMail
    {
        $expectedInbox = config('services.mailtrap.inbox_id');

        if (is_numeric($expectedInbox) && (int) $expectedInbox !== $inboxId) {
            return null;
        }

        $existing = InboundMail::query()->where('mailtrap_message_id', $messageId)->first();

        if ($existing !== null && ! $existing->attachments()->whereNull('path')->exists()) {
            return $existing;
        }

        $data = $this->client->message($inboxId, $messageId);
        $mail = $existing ?? $this->store($inboxId, $messageId, $data);

        $this->storeAttachments($mail, is_array($data['attachments'] ?? null) ? $data['attachments'] : []);

        return $mail;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(int $inboxId, string $messageId, array $data): InboundMail
    {
        return DB::transaction(function () use ($inboxId, $messageId, $data): InboundMail {
            $from = MailAddress::parse($data['from'] ?? null) ?? new MailAddress('desconocido@invalid');
            $subject = mb_substr(self::text($data['subject'] ?? null), 0, 500);
            $inReplyTo = self::nullable($data['in_reply_to'] ?? null);
            $references = array_values(array_filter(is_array($data['references'] ?? null) ? $data['references'] : [], 'is_string'));
            $receivedAt = self::date($data['received_at'] ?? null);
            $html = self::nullable($data['html_body'] ?? null);
            $text = self::nullable($data['text_body'] ?? null);
            $mailtrapThread = self::nullable($data['thread_id'] ?? null);

            $thread = $this->threads->resolve(MailThreads::ids($inReplyTo, $references), $mailtrapThread, $subject, $from->email, $receivedAt);

            $mail = InboundMail::query()->create([
                'mailtrap_message_id' => $messageId,
                'mailtrap_inbox_id' => $inboxId,
                'mailtrap_thread_id' => $mailtrapThread,
                'mail_thread_id' => $thread->getKey(),
                'from_address' => $from->email,
                'from_name' => $from->name,
                'to' => MailAddress::emails($data['to'] ?? []),
                'cc' => MailAddress::emails($data['cc'] ?? []),
                'reply_to' => MailAddress::parse($data['reply_to'] ?? null)?->email,
                'subject' => $subject,
                'message_id' => self::messageId($data['rfc_message_id'] ?? $data['message_id'] ?? null),
                'in_reply_to' => self::messageId($inReplyTo),
                'references' => $references,
                'headers' => is_array($data['headers'] ?? null) ? $data['headers'] : [],
                'html_body' => $html,
                'text_body' => $text,
                'size' => is_numeric($data['size'] ?? null) ? (int) $data['size'] : null,
                'payload' => $data,
                'received_at' => $receivedAt,
            ]);

            $this->threads->touch($thread, MailBody::textOf($text, $html));
            MailActivity::announce(MailActivityKind::Inbound, threads: [(int) $thread->getKey()]);

            return $mail;
        });
    }

    /**
     * @param  array<mixed>  $attachments
     */
    private function storeAttachments(InboundMail $mail, array $attachments): void
    {
        $failures = 0;

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || self::nullable($attachment['attachment_id'] ?? null) === null) {
                continue;
            }

            $row = InboundMailAttachment::query()->firstOrCreate(
                ['inbound_mail_id' => $mail->getKey(), 'mailtrap_attachment_id' => (string) $attachment['attachment_id']],
                [
                    'filename' => mb_substr(basename(str_replace('\\', '/', self::nullable($attachment['filename'] ?? null) ?? 'adjunto')), 0, 255),
                    'content_type' => mb_substr(self::nullable($attachment['content_type'] ?? null) ?? 'application/octet-stream', 0, 150),
                    'disposition' => ($attachment['content_disposition'] ?? null) === 'inline' ? 'inline' : 'attachment',
                    'content_id' => self::messageId($attachment['content_id'] ?? null),
                    'size' => is_numeric($attachment['size'] ?? null) ? (int) $attachment['size'] : null,
                ],
            );

            if ($row->isStored()) {
                continue;
            }

            try {
                $url = self::nullable($attachment['download_url'] ?? null) ?? throw new RuntimeException('Mailtrap no dio enlace de descarga.');
                $path = sprintf('inbound/%d/%d-%s', $mail->getKey(), $row->getKey(), self::safeName($row->filename));
                Storage::disk(InboundMailAttachment::DISK)->put($path, $this->client->download($url));
                $row->forceFill(['path' => $path])->save();
            } catch (Throwable $exception) {
                report($exception);
                $failures++;
            }
        }

        if ($failures > 0) {
            throw new RuntimeException(sprintf('No se pudieron guardar %d adjunto(s) del correo %s.', $failures, $mail->mailtrap_message_id));
        }
    }

    private static function safeName(string $filename): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename(str_replace('\\', '/', $filename))) ?? 'adjunto';

        return mb_substr(trim($name, '._') === '' ? 'adjunto' : $name, 0, 120);
    }

    private static function messageId(mixed $value): ?string
    {
        $value = self::nullable($value);

        return $value === null ? null : mb_substr(trim($value, '<> '), 0, 255);
    }

    private static function date(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->setTimezone(config('app.timezone')) : CarbonImmutable::now();
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function nullable(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
