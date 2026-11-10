<?php

declare(strict_types=1);

namespace App\Support\Ai;

use App\Domain\Catalog\PhotoSubject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class HeldCopySuggestions
{
    public const HOLD_SECONDS = 7_200;

    public const PASS_SECONDS = 86_400;

    /**
     * @param  array{description: string, characteristics: list<string>, tags: list<string>}  $copy
     */
    public function hold(int $organizationId, int $userId, ?int $productId, PhotoSubject $subject, string $observation, array $copy): string
    {
        $token = Str::random(40);

        Cache::put($this->heldKey($token), [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'product_id' => $productId,
            'subject' => $subject->value,
            'observation' => $observation,
            'copy' => $copy,
        ], self::HOLD_SECONDS);

        return $token;
    }

    /**
     * @return array{product_id: int|null, subject: PhotoSubject, observation: string, copy: array{description: string, characteristics: list<string>, tags: list<string>}}|null
     */
    public function release(string $token, int $organizationId, int $userId): ?array
    {
        $held = $this->owned($this->heldKey($token), $organizationId, $userId);

        if ($held === null || ! is_array($held['copy'] ?? null)) {
            return null;
        }

        Cache::forget($this->heldKey($token));

        /** @var array{description: string, characteristics: list<string>, tags: list<string>} $copy */
        $copy = $held['copy'];

        return [
            'product_id' => is_int($held['product_id'] ?? null) ? $held['product_id'] : null,
            'subject' => PhotoSubject::fromAnswer($held['subject'] ?? null),
            'observation' => is_string($held['observation'] ?? null) ? $held['observation'] : '',
            'copy' => $copy,
        ];
    }

    public function grantPass(string $token, int $organizationId, int $userId, PhotoSubject $subject, string $observation): void
    {
        Cache::put($this->passKey($token), [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'subject' => $subject->value,
            'observation' => $observation,
        ], self::PASS_SECONDS);
    }

    /**
     * @return array{subject: PhotoSubject, observation: string}|null
     */
    public function redeemPass(string $token, int $organizationId, int $userId): ?array
    {
        $pass = $this->owned($this->passKey($token), $organizationId, $userId);

        if ($pass === null) {
            return null;
        }

        Cache::forget($this->passKey($token));

        return [
            'subject' => PhotoSubject::fromAnswer($pass['subject'] ?? null),
            'observation' => is_string($pass['observation'] ?? null) ? $pass['observation'] : '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function owned(string $key, int $organizationId, int $userId): ?array
    {
        $entry = Cache::get($key);

        if (! is_array($entry) || ($entry['organization_id'] ?? null) !== $organizationId || ($entry['user_id'] ?? null) !== $userId) {
            return null;
        }

        return $entry;
    }

    private function heldKey(string $token): string
    {
        return 'ai-copy-held:'.$token;
    }

    private function passKey(string $token): string
    {
        return 'ai-photo-pass:'.$token;
    }
}
