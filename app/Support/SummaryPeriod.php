<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Throwable;

final readonly class SummaryPeriod
{
    public const DEFAULT_PRESET = '6m';

    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];

    /**
     * @param  array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>  $presets
     */
    private function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        private array $presets,
    ) {}

    public static function fromRequest(Request $request, CarbonImmutable $today, ?CarbonImmutable $earliest, string $defaultPreset = self::DEFAULT_PRESET): self
    {
        $presets = self::presets($today, $earliest);
        $from = self::day($request->query('desde'), $today);
        $to = self::day($request->query('hasta'), $today);

        if ($from === null || $to === null) {
            $default = $presets[$defaultPreset] ?? $presets[self::DEFAULT_PRESET];

            return new self($default['from'], $default['to'], $presets);
        }

        return $from->greaterThan($to) ? new self($to, $from, $presets) : new self($from, $to, $presets);
    }

    public function preset(): ?string
    {
        foreach ($this->presets as $key => $preset) {
            if ($preset['from']->equalTo($this->from) && $preset['to']->equalTo($this->to)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{from: string, to: string, preset: string|null, granularity: string, presets: list<array{key: string, label: string, from: string, to: string}>}
     */
    public function toArray(string $granularity): array
    {
        $presets = [];

        foreach ($this->presets as $key => $preset) {
            $presets[] = ['key' => $key, 'label' => $preset['label'], 'from' => $preset['from']->toDateString(), 'to' => $preset['to']->toDateString()];
        }

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'preset' => $this->preset(),
            'granularity' => $granularity,
            'presets' => $presets,
        ];
    }

    public static function labelOf(string $key): string
    {
        $parts = array_map('intval', explode('-', $key));

        return count($parts) === 3
            ? sprintf('%d %s', $parts[2], self::MONTHS[$parts[1] - 1] ?? '')
            : sprintf('%s %02d', self::MONTHS[$parts[1] - 1] ?? '', $parts[0] % 100);
    }

    /**
     * @return array<string, array{label: string, from: CarbonImmutable, to: CarbonImmutable}>
     */
    private static function presets(CarbonImmutable $today, ?CarbonImmutable $earliest): array
    {
        $sixMonths = $today->startOfMonth()->subMonths(5);

        return [
            '30d' => ['label' => 'Últimos 30 días', 'from' => $today->subDays(29), 'to' => $today],
            'mes' => ['label' => 'Este mes', 'from' => $today->startOfMonth(), 'to' => $today],
            'mes-pasado' => ['label' => 'Mes pasado', 'from' => $today->startOfMonth()->subMonth(), 'to' => $today->startOfMonth()->subDay()],
            '6m' => ['label' => 'Últimos 6 meses', 'from' => $sixMonths, 'to' => $today],
            'anio' => ['label' => 'Este año', 'from' => $today->startOfYear(), 'to' => $today],
            'todo' => ['label' => 'Desde el primer pago', 'from' => $earliest === null || $earliest->greaterThan($sixMonths) ? $sixMonths : $earliest->startOfDay(), 'to' => $today],
        ];
    }

    private static function day(mixed $value, CarbonImmutable $today): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone());
        } catch (Throwable) {
            return null;
        }

        return $day === null || $day->format('Y-m-d') !== $value ? null : $day;
    }
}
