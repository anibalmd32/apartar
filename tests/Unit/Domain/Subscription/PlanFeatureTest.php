<?php

declare(strict_types=1);

use App\Domain\Subscription\PlanFeature;

it('declara exactamente las seis funciones del contrato', function (): void {
    $values = array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases());

    expect($values)->toBe([
        'branding', 'excel_export', 'web_push', 'subscriber_digest', 'full_analytics', 'custom_domain',
    ]);
});

it('no guarda el sello de plataforma como función independiente', function (): void {
    $values = array_map(fn (PlanFeature $feature): string => $feature->value, PlanFeature::cases());

    expect($values)->not->toContain('made_with_badge');
    expect($values)->not->toContain('platform_badge');
});

it('parte de todas las funciones apagadas', function (): void {
    $none = PlanFeature::noneEnabled();

    expect($none)->toHaveCount(6);
    expect(array_unique(array_values($none)))->toBe([false]);
});

it('enciende solo las que se le piden', function (): void {
    $features = PlanFeature::only([PlanFeature::Branding, PlanFeature::ExcelExport]);

    expect($features['branding'])->toBeTrue();
    expect($features['excel_export'])->toBeTrue();
    expect($features['web_push'])->toBeFalse();
    expect($features['custom_domain'])->toBeFalse();
    expect($features)->toHaveCount(6);
});

it('nombra cada función para una persona', function (): void {
    $labels = PlanFeature::labels();

    expect($labels)->toHaveCount(6);
    expect(array_unique($labels))->toHaveCount(6);

    foreach (PlanFeature::cases() as $feature) {
        expect($labels[$feature->value])->toBe($feature->label())->not->toBe($feature->value);
    }

    expect(PlanFeature::ExcelExport->label())->toBe('Exportación a Excel');
    expect(PlanFeature::CustomDomain->label())->toBe('Dominio propio');
});
