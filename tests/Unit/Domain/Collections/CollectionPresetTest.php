<?php

declare(strict_types=1);

use App\Domain\Collections\CollectionPreset;

it('tiene los seis presets de la spec, cada uno con nombre y definición', function (): void {
    expect(array_map(fn (CollectionPreset $preset): string => $preset->value, CollectionPreset::cases()))->toBe([
        'due_today', 'due_within_7_days', 'overdue_over_15_days', 'fortnight_first_half', 'fortnight_second_half', 'completed_not_delivered',
    ]);

    foreach (CollectionPreset::cases() as $preset) {
        expect($preset->label())->not->toBe('')
            ->and($preset->definition())->toEndWith('.');
    }
});

it('solo el de pagados sin entregar lista apartados', function (): void {
    expect(array_values(array_filter(CollectionPreset::cases(), fn (CollectionPreset $preset): bool => $preset->listsLayaways())))
        ->toBe([CollectionPreset::CompletedNotDelivered]);
});
