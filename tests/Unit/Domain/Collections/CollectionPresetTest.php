<?php

declare(strict_types=1);

use App\Domain\Collections\CollectionPreset;

it('tiene los siete presets, cada uno con nombre y definición', function (): void {
    expect(array_map(fn (CollectionPreset $preset): string => $preset->value, CollectionPreset::cases()))->toBe([
        'awaiting_down_payment', 'due_today', 'due_within_7_days', 'overdue_over_15_days', 'fortnight_first_half', 'fortnight_second_half', 'completed_not_delivered',
    ]);

    foreach (CollectionPreset::cases() as $preset) {
        expect($preset->label())->not->toBe('')
            ->and($preset->definition(15))->toEndWith('.');
    }
});

it('esperando inicial y pagados sin entregar listan apartados, no cuotas', function (): void {
    expect(array_values(array_filter(CollectionPreset::cases(), fn (CollectionPreset $preset): bool => $preset->listsLayaways())))
        ->toBe([CollectionPreset::AwaitingDownPayment, CollectionPreset::CompletedNotDelivered]);

    expect(CollectionPreset::AwaitingDownPayment->awaitsDownPayment())->toBeTrue()
        ->and(CollectionPreset::CompletedNotDelivered->awaitsDownPayment())->toBeFalse();
});

it('la mora prolongada usa los días de la configuración del negocio', function (): void {
    expect(CollectionPreset::LongOverdue->label())->toBe('Mora prolongada')
        ->and(CollectionPreset::LongOverdue->definition(30))->toContain('más de 30 días')
        ->and(CollectionPreset::LongOverdue->definition(1))->toContain('más de 1 día de atraso');
});
