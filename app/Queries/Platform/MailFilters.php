<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Domain\Mail\MailStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final readonly class MailFilters
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 366;

    public function __construct(
        public ?MailStatus $status = null,
        public ?string $category = null,
        public ?string $search = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('categoria');
        $search = $request->query('q');

        return new self(
            status: MailStatus::tryFrom((string) $request->query('estado')),
            category: is_string($category) && preg_match('/^[A-Za-z0-9_]{1,100}$/', $category) === 1 ? $category : null,
            search: is_string($search) && trim($search) !== '' ? mb_substr(trim($search), 0, 100) : null,
            from: self::date($request->query('desde')),
            to: self::date($request->query('hasta')),
            page: max(1, min(1000, $request->integer('pagina', 1))),
        );
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function range(): array
    {
        $to = $this->to ?? CarbonImmutable::today();
        $from = $this->from ?? $to->subDays(self::DEFAULT_DAYS - 1);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->lessThan($to->subDays(self::MAX_DAYS - 1)) ? $to->subDays(self::MAX_DAYS - 1) : $from, $to];
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'estado' => $this->status?->value,
            'categoria' => $this->category,
            'q' => $this->search,
            'desde' => $this->from?->toDateString(),
            'hasta' => $this->to?->toDateString(),
        ];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $value)?->startOfDay() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
