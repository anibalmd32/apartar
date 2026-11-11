<?php

declare(strict_types=1);

namespace App\Queries\Activity;

use Carbon\CarbonInterface;

final readonly class ActivityEvent
{
    /**
     * @return array{kind: string, title: string, detail: string|null, actor: string|null, at: string, link: array{url: string, label: string}|null}
     */
    public static function make(string $kind, string $title, ?string $detail, ?string $actor, ?CarbonInterface $at, ?string $url = null, ?string $linkLabel = null): array
    {
        return [
            'kind' => $kind,
            'title' => $title,
            'detail' => $detail === null || trim($detail) === '' ? null : $detail,
            'actor' => $actor,
            'at' => (string) $at?->toIso8601String(),
            'link' => $url === null ? null : ['url' => $url, 'label' => $linkLabel ?? $url],
        ];
    }

    public static function reason(?string $voidReason): ?string
    {
        if ($voidReason === null) {
            return null;
        }

        $reason = (string) preg_replace('/^Se (movió a \S+|corrigió): /u', '', $voidReason);

        return $reason === '' ? null : 'Motivo: '.$reason;
    }
}
