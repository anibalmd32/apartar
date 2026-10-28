<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Models\InboundMail;
use App\Models\MailThread;
use App\Models\SentMail;
use Carbon\CarbonInterface;

final class MailThreads
{
    public const SNIPPET_LENGTH = 200;

    /**
     * @param  array<mixed>  $references
     * @return list<string>
     */
    public static function ids(?string $inReplyTo, array $references): array
    {
        $ids = [];

        foreach ([$inReplyTo, ...$references] as $value) {
            foreach (is_string($value) ? (preg_split('/[\s,]+/', $value) ?: []) : [] as $id) {
                $id = trim($id, "<> \t");

                if ($id !== '' && ! in_array($id, $ids, true)) {
                    $ids[] = mb_substr($id, 0, 255);
                }
            }
        }

        return $ids;
    }

    /**
     * @param  list<string>  $messageIds
     */
    public function resolve(array $messageIds, ?string $mailtrapThreadId, string $subject, string $counterpart, CarbonInterface $at): MailThread
    {
        if ($messageIds !== []) {
            $threadId = InboundMail::query()->whereIn('message_id', $messageIds)->value('mail_thread_id');

            if ($threadId !== null) {
                return MailThread::query()->findOrFail($threadId);
            }

            $sent = SentMail::query()->whereIn('message_id', $messageIds)->latest('id')->first();

            if ($sent?->mail_thread_id !== null) {
                return MailThread::query()->findOrFail($sent->mail_thread_id);
            }
        }

        $thread = $mailtrapThreadId === null ? null : MailThread::query()->where('mailtrap_thread_id', $mailtrapThreadId)->first();

        $thread ??= MailThread::query()->create([
            'mailtrap_thread_id' => $mailtrapThreadId,
            'subject' => mb_substr($subject === '' ? '(sin asunto)' : $subject, 0, 500),
            'counterpart' => mb_substr($counterpart, 0, 255),
            'last_message_at' => $at,
        ]);

        if (isset($sent)) {
            $sent->forceFill(['mail_thread_id' => $thread->getKey()])->save();
        }

        return $thread;
    }

    public function touch(MailThread $thread, string $snippet): void
    {
        $thread->snippet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $snippet)), 0, self::SNIPPET_LENGTH);
        $thread->refreshCounters();
    }
}
