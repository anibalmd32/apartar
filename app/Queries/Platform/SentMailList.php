<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Mail\MailCategory;
use App\Models\Organization;
use App\Models\SentMail;
use App\Models\SentMailEvent;
use Carbon\CarbonImmutable;

final class SentMailList
{
    public const PER_PAGE = 50;

    /**
     * @return array{rows: list<array<string, mixed>>, hasMore: bool}
     */
    public function list(MailFilters $filters): array
    {
        $query = SentMail::query()
            ->select(['id', 'uuid', 'to', 'cc', 'bcc', 'subject', 'category', 'status', 'opens_count', 'clicks_count', 'created_at', 'platform_user_id'])
            ->when($filters->category !== null, fn ($query) => $query->where('category', $filters->category))
            ->when($filters->from !== null, fn ($query) => $query->where('created_at', '>=', $filters->from?->startOfDay()))
            ->when($filters->to !== null, fn ($query) => $query->where('created_at', '<=', $filters->to?->endOfDay()))
            ->when($filters->search !== null, function ($query) use ($filters): void {
                $term = '%'.addcslashes((string) $filters->search, '%_\\').'%';
                $query->where(fn ($inner) => $inner->where('subject', 'like', $term)->orWhereRaw('CAST(`to` AS CHAR) LIKE ?', [$term]));
            });

        if ($filters->status !== null) {
            SentMail::whereEffectiveStatus($query, $filters->status);
        }

        $mails = $query->latest('created_at')->latest('id')
            ->offset(($filters->page - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE + 1)
            ->get();

        $now = CarbonImmutable::now();

        return [
            'rows' => $mails->take(self::PER_PAGE)->map(function (SentMail $mail) use ($now): array {
                $status = $mail->effectiveStatus($now);

                return [
                    'uuid' => $mail->uuid,
                    'to' => $mail->to[0] ?? '',
                    'otherRecipients' => max(0, count($mail->to) + count($mail->cc) + count($mail->bcc) - 1),
                    'subject' => $mail->subject,
                    'category' => MailCategory::label($mail->category),
                    'manual' => $mail->category === MailCategory::MANUAL,
                    'status' => $status->value,
                    'statusLabel' => $status->label(),
                    'problem' => $status->isProblem(),
                    'opens' => $mail->opens_count,
                    'clicks' => $mail->clicks_count,
                    'createdAt' => $mail->created_at->toIso8601String(),
                ];
            })->values()->all(),
            'hasMore' => $mails->count() > self::PER_PAGE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(SentMail $mail): array
    {
        $status = $mail->effectiveStatus();
        $organization = $mail->target_organization_id === null ? null : Organization::query()->withTrashed()->find($mail->target_organization_id, ['id', 'name', 'slug']);

        return [
            'uuid' => $mail->uuid,
            'subject' => $mail->subject,
            'from' => $mail->from_name === null ? $mail->from_address : $mail->from_name.' <'.$mail->from_address.'>',
            'to' => $mail->to,
            'cc' => $mail->cc,
            'bcc' => $mail->bcc,
            'category' => MailCategory::label($mail->category),
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'problem' => $status->isProblem(),
            'html' => $mail->html_body,
            'text' => $mail->text_body,
            'headers' => $mail->headers,
            'messageId' => $mail->message_id,
            'mailtrapMessageId' => $mail->mailtrap_message_id,
            'opens' => $mail->opens_count,
            'clicks' => $mail->clicks_count,
            'createdAt' => $mail->created_at->toIso8601String(),
            'sentAt' => $mail->sent_at?->toIso8601String(),
            'operator' => $mail->operator?->name,
            'threadId' => $mail->mail_thread_id,
            'organization' => $organization === null ? null : ['name' => $organization->name, 'slug' => $organization->slug],
            'events' => $mail->events()->orderBy('occurred_at')->orderBy('id')->get()->map(fn (SentMailEvent $event): array => [
                'id' => $event->getKey(),
                'type' => $event->type->value,
                'label' => $event->type->label(),
                'occurredAt' => $event->occurred_at->toIso8601String(),
                'email' => $event->email,
                'url' => $event->url,
                'reason' => $event->reason,
                'responseCode' => $event->response_code,
                'bounceCategory' => $event->bounce_category,
                'ip' => $event->ip,
                'userAgent' => $event->user_agent,
            ])->values()->all(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function categories(): array
    {
        return SentMail::query()->distinct()->orderBy('category')->pluck('category')
            ->map(fn (string $category): array => ['value' => $category, 'label' => MailCategory::label($category)])
            ->values()
            ->all();
    }
}
