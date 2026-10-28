<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Models\InboundMail;
use App\Models\InboundMailAttachment;
use App\Models\MailThread;
use App\Models\SentMail;

final class MailInbox
{
    public const PER_PAGE = 30;

    /**
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function threads(bool $unreadOnly, ?string $search, int $page): array
    {
        $threads = MailThread::query()
            ->when($unreadOnly, fn ($query) => $query->where('unread_count', '>', 0))
            ->when($search !== null, function ($query) use ($search): void {
                $term = '%'.addcslashes((string) $search, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('subject', 'like', $term)->orWhere('counterpart', 'like', $term)->orWhere('snippet', 'like', $term));
            })
            ->latest('last_message_at')
            ->latest('id')
            ->offset(($page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $threads->take(self::PER_PAGE)->map(fn (MailThread $thread): array => [
                'id' => $thread->getKey(),
                'subject' => $thread->subject,
                'counterpart' => $thread->counterpart,
                'snippet' => $thread->snippet,
                'messages' => $thread->messages_count,
                'unread' => $thread->unread_count,
                'lastMessageAt' => $thread->last_message_at->toIso8601String(),
            ])->values()->all(),
            'hasMore' => $threads->count() > self::PER_PAGE,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function conversation(MailThread $thread): array
    {
        $inbound = $thread->inboundMails()->with('attachments')->get()->map(fn (InboundMail $mail): array => [
            'key' => 'in-'.$mail->getKey(),
            'kind' => 'inbound',
            'id' => $mail->getKey(),
            'from' => $mail->from_name === null ? $mail->from_address : $mail->from_name.' <'.$mail->from_address.'>',
            'fromAddress' => $mail->from_address,
            'to' => $mail->to,
            'cc' => $mail->cc,
            'subject' => $mail->subject,
            'html' => $mail->html_body,
            'text' => $mail->text_body,
            'at' => $mail->received_at->toIso8601String(),
            'unread' => $mail->read_at === null,
            'attachments' => $mail->attachments->map(fn (InboundMailAttachment $file): array => [
                'id' => $file->getKey(),
                'filename' => $file->filename,
                'contentType' => $file->content_type,
                'size' => $file->size,
                'stored' => $file->isStored(),
            ])->values()->all(),
        ]);

        $sent = $thread->sentMails()->with('operator:id,name')->get()->map(fn (SentMail $mail): array => [
            'key' => 'out-'.$mail->getKey(),
            'kind' => 'sent',
            'uuid' => $mail->uuid,
            'from' => $mail->from_address,
            'to' => $mail->to,
            'cc' => $mail->cc,
            'subject' => $mail->subject,
            'html' => $mail->html_body,
            'text' => $mail->text_body,
            'at' => $mail->created_at->toIso8601String(),
            'status' => $mail->effectiveStatus()->label(),
            'operator' => $mail->operator?->name,
        ]);

        return $inbound->concat($sent)->sortBy('at')->values()->all();
    }
}
