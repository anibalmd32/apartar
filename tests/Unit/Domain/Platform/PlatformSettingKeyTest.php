<?php

declare(strict_types=1);

use App\Domain\Platform\PlatformSettingKey;

it('tiene el default de cada ajuste dentro de su rango', function (): void {
    foreach (PlatformSettingKey::cases() as $key) {
        expect($key->accepts($key->default()))->toBeTrue($key->value);
    }
});

it('conserva los valores por defecto del sistema', function (): void {
    expect(PlatformSettingKey::ReceiptMaxSizeMb->default())->toBe(5)
        ->and(PlatformSettingKey::SubscriptionGraceDays->default())->toBe(7)
        ->and(PlatformSettingKey::DowngradeGraceDays->default())->toBe(7)
        ->and(PlatformSettingKey::ExportLinkTtlHours->default())->toBe(24)
        ->and(PlatformSettingKey::SupportAccessTtlHours->default())->toBe(8)
        ->and(PlatformSettingKey::TelemetryRetentionDaysDefault->default())->toBe(365);
});

it('rechaza lo que se sale del rango o no es entero', function (): void {
    expect(PlatformSettingKey::SubscriptionGraceDays->accepts(0))->toBeFalse()
        ->and(PlatformSettingKey::SubscriptionGraceDays->accepts(61))->toBeFalse()
        ->and(PlatformSettingKey::SubscriptionGraceDays->accepts('7'))->toBeFalse();
});
