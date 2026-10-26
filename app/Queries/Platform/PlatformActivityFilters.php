<?php

declare(strict_types=1);

namespace App\Queries\Platform;

use App\Support\Auditing\PlatformAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final readonly class PlatformActivityFilters
{
    public function __construct(
        public ?int $operatorId = null,
        public ?string $action = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $operator = $request->integer('operador');
        $action = $request->query('accion');

        return new self(
            operatorId: $operator > 0 ? $operator : null,
            action: is_string($action) && array_key_exists($action, PlatformAudit::LABELS) ? $action : null,
            from: self::date($request->query('desde')),
            to: self::date($request->query('hasta')),
            page: max(1, min(1000, $request->integer('pagina', 1))),
        );
    }

    /**
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'operador' => $this->operatorId,
            'accion' => $this->action,
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
            return CarbonImmutable::createFromFormat('Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
