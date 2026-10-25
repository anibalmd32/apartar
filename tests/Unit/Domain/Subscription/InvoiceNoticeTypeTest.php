<?php

declare(strict_types=1);

use App\Domain\Subscription\InvoiceNoticeType;

it('mantiene los valores que persiste la columna', function (): void {
    expect(array_map(fn (InvoiceNoticeType $type): string => $type->value, InvoiceNoticeType::cases()))
        ->toBe(['issued', 'overdue', 'grace_3_days', 'grace_1_day']);
});

it('elige el recordatorio del fin de la gracia por tramos, para no perderlo si la pasada no corrió el día exacto', function (int $daysLeft, ?InvoiceNoticeType $expected): void {
    expect(InvoiceNoticeType::graceReminderFor($daysLeft))->toBe($expected);
})->with([
    'seis días' => [6, null],
    'cuatro días' => [4, null],
    'tres días' => [3, InvoiceNoticeType::GraceEndsInThreeDays],
    'dos días: el de tres, que es el que faltaba' => [2, InvoiceNoticeType::GraceEndsInThreeDays],
    'un día' => [1, InvoiceNoticeType::GraceEndsInOneDay],
    'el último día' => [0, InvoiceNoticeType::GraceEndsInOneDay],
    'ya agotada' => [-1, null],
]);

it('da a cada aviso su propio asunto', function (): void {
    $subjects = array_map(fn (InvoiceNoticeType $type): string => $type->subject(), InvoiceNoticeType::cases());

    expect(array_unique($subjects))->toHaveCount(4)
        ->and($subjects)->each->toContain('Apartar');
});
