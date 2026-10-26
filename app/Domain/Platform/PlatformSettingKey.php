<?php

declare(strict_types=1);

namespace App\Domain\Platform;

use App\Domain\Payment\ReceiptFile;
use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Subscription\DunningAssessment;
use App\Domain\Telemetry\TelemetryRetention;
use App\Models\ExportJob;

enum PlatformSettingKey: string
{
    case ReceiptMaxSizeMb = 'receipt_max_size_mb';

    case SubscriptionGraceDays = 'subscription_grace_days';

    case DowngradeGraceDays = 'downgrade_grace_days';

    case ExportLinkTtlHours = 'export_link_ttl_hours';

    case SupportAccessTtlHours = 'support_access_ttl_hours';

    case TelemetryRetentionDaysDefault = 'telemetry_retention_days_default';

    public function default(): int
    {
        return match ($this) {
            self::ReceiptMaxSizeMb => ReceiptFile::MAX_SIZE_MB,
            self::SubscriptionGraceDays => DunningAssessment::GRACE_DAYS,
            self::DowngradeGraceDays => DowngradeOutcome::GRACE_DAYS,
            self::ExportLinkTtlHours => ExportJob::LINK_TTL_HOURS,
            self::SupportAccessTtlHours => 8,
            self::TelemetryRetentionDaysDefault => TelemetryRetention::PLATFORM_DEFAULT_DAYS,
        };
    }

    /**
     * @return array{int, int}
     */
    public function range(): array
    {
        return match ($this) {
            self::ReceiptMaxSizeMb => [1, 20],
            self::SubscriptionGraceDays, self::DowngradeGraceDays => [1, 60],
            self::ExportLinkTtlHours => [1, 168],
            self::SupportAccessTtlHours => [1, 72],
            self::TelemetryRetentionDaysDefault => [30, 730],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ReceiptMaxSizeMb => 'Tamaño máximo de un comprobante',
            self::SubscriptionGraceDays => 'Días de gracia tras vencer una factura',
            self::DowngradeGraceDays => 'Días antes de despublicar tras bajar de plan',
            self::ExportLinkTtlHours => 'Validez del enlace de una exportación',
            self::SupportAccessTtlHours => 'Duración de un acceso de soporte',
            self::TelemetryRetentionDaysDefault => 'Retención de eventos sin negocio',
        };
    }

    public function unit(): string
    {
        return match ($this) {
            self::ReceiptMaxSizeMb => 'MB',
            self::SubscriptionGraceDays, self::DowngradeGraceDays, self::TelemetryRetentionDaysDefault => 'días',
            self::ExportLinkTtlHours, self::SupportAccessTtlHours => 'horas',
        };
    }

    public function accepts(mixed $value): bool
    {
        [$min, $max] = $this->range();

        return is_int($value) && $value >= $min && $value <= $max;
    }
}
