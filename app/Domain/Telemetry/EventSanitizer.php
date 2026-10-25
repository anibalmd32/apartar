<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

use App\Domain\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EventSanitizer
{
    public const MAX_PAYLOAD_BYTES = 4096;

    public const WINDOW_HOURS = 24;

    public function __construct(private readonly TenantResolver $resolver) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public function sanitize(array $raw, CarbonImmutable $now): ?SanitizedEvent
    {
        $name = TelemetryEventName::tryFrom(is_string($raw['name'] ?? null) ? $raw['name'] : '');

        if ($name === null) {
            return null;
        }

        $occurredAt = $this->occurredAt($raw['occurredAt'] ?? null, $now);

        if ($occurredAt === null) {
            return null;
        }

        [$organizationId, $catalogId] = $this->catalogOf($raw['catalogSlug'] ?? null);

        if ($name->requiresCatalog() && $catalogId === null) {
            return null;
        }

        $subjectId = $this->subjectOf($name, $raw['subjectSlug'] ?? null, $organizationId, $catalogId);

        return new SanitizedEvent(
            name: $name,
            organizationId: $organizationId,
            catalogId: $catalogId,
            subjectType: $subjectId === null ? null : $name->subjectType(),
            subjectId: $subjectId,
            payload: $this->payload($name, is_array($raw['payload'] ?? null) ? $raw['payload'] : []),
            occurredAt: $occurredAt,
        );
    }

    private function occurredAt(mixed $value, CarbonImmutable $now): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return $now;
        }

        try {
            $at = CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }

        if ($at->greaterThan($now->addMinute()) || $at->lessThan($now->subHours(self::WINDOW_HOURS))) {
            return null;
        }

        return $at;
    }

    /**
     * @return array{int|null, int|null}
     */
    private function catalogOf(mixed $slug): array
    {
        if (! is_string($slug) || $slug === '' || mb_strlen($slug) > 120) {
            return [null, null];
        }

        $catalog = $this->resolver->telemetryCatalog($slug);

        if ($catalog === null || ! $catalog->visibility()->isPubliclyAccessible()) {
            return [null, null];
        }

        return [(int) $catalog->organization_id, (int) $catalog->getKey()];
    }

    private function subjectOf(TelemetryEventName $name, mixed $slug, ?int $organizationId, ?int $catalogId): ?int
    {
        return match ($name->subjectType()) {
            'catalog' => $catalogId,
            'product' => $organizationId !== null && is_string($slug) && $slug !== '' && mb_strlen($slug) <= 191
                ? $this->productId($organizationId, $slug)
                : null,
            default => null,
        };
    }

    private function productId(int $organizationId, string $slug): ?int
    {
        $id = DB::table('products')->where('organization_id', $organizationId)->where('slug', $slug)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, mixed>
     */
    private function payload(TelemetryEventName $name, array $raw): array
    {
        $clean = [];

        foreach ($name->payloadRules() as $key => $type) {
            $value = $raw[$key] ?? null;

            $ok = match ($type) {
                'string' => is_string($value) && mb_strlen($value) <= 200,
                'int' => is_int($value),
                'list' => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
            };

            if ($ok) {
                $clean[$key] = $value;
            }
        }

        if (strlen((string) json_encode($clean)) > self::MAX_PAYLOAD_BYTES) {
            return ['truncated' => true];
        }

        return $clean;
    }
}
