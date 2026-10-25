<?php

declare(strict_types=1);

use App\Domain\Layaway\DueCalendar;
use Carbon\CarbonImmutable;

it('toma como «pronto» el recordatorio más temprano antes del vencimiento', function (mixed $offsets, int $days): void {
    expect(DueCalendar::soonDaysFrom($offsets))->toBe($days);
})->with([
    'el ajuste por defecto' => [[-3, 0, 1, 7, 15], 3],
    'el más negativo, no el primero' => [[-1, -5, 0], 5],
    'sin recordatorios previos, solo el mismo día' => [[0, 1, 7], 0],
    'valores que llegan como texto' => [['-4', '0'], 4],
    'un valor que no es una lista' => [null, 0],
]);

it('cuenta los días en el calendario del negocio, negativos si ya pasó', function (): void {
    $calendar = new DueCalendar(CarbonImmutable::parse('2026-09-25', 'America/Caracas')->startOfDay(), 3);

    expect($calendar->daysUntil(CarbonImmutable::parse('2026-09-25')))->toBe(0)
        ->and($calendar->daysUntil(CarbonImmutable::parse('2026-09-28')))->toBe(3)
        ->and($calendar->daysUntil(CarbonImmutable::parse('2026-09-24')))->toBe(-1)
        ->and($calendar->daysUntil(CarbonImmutable::parse('2026-09-26 23:00:00', 'Asia/Tokyo')))->toBe(1);
});

it('separa vencida, pronto y lo demás en los bordes exactos del umbral', function (): void {
    $calendar = new DueCalendar(CarbonImmutable::parse('2026-09-25', 'America/Caracas')->startOfDay(), 3);

    expect($calendar->isPastDue(CarbonImmutable::parse('2026-09-24')))->toBeTrue()
        ->and($calendar->isPastDue(CarbonImmutable::parse('2026-09-25')))->toBeFalse()
        ->and($calendar->isDueSoon(CarbonImmutable::parse('2026-09-24')))->toBeFalse()
        ->and($calendar->isDueSoon(CarbonImmutable::parse('2026-09-25')))->toBeTrue()
        ->and($calendar->isDueSoon(CarbonImmutable::parse('2026-09-28')))->toBeTrue()
        ->and($calendar->isDueSoon(CarbonImmutable::parse('2026-09-29')))->toBeFalse()
        ->and($calendar->lastSoonDay()->toDateString())->toBe('2026-09-28');
});
