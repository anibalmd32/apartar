<?php

declare(strict_types=1);

namespace App\Domain\Subscription;

enum PlanFeature: string
{
    case Branding = 'branding';

    case ExcelExport = 'excel_export';

    case WebPush = 'web_push';

    case SubscriberDigest = 'subscriber_digest';

    case FullAnalytics = 'full_analytics';

    case CustomDomain = 'custom_domain';

    public function label(): string
    {
        return match ($this) {
            self::Branding => 'Tu logo y tu color, sin el sello de Apartar',
            self::ExcelExport => 'Exportación a Excel',
            self::WebPush => 'Notificaciones push',
            self::SubscriberDigest => 'Resumen de novedades para quienes siguen tus catálogos',
            self::FullAnalytics => 'Analítica completa',
            self::CustomDomain => 'Dominio propio',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::cases() as $feature) {
            $labels[$feature->value] = $feature->label();
        }

        return $labels;
    }

    /**
     * @return array<string, bool>
     */
    public static function noneEnabled(): array
    {
        $features = [];

        foreach (self::cases() as $feature) {
            $features[$feature->value] = false;
        }

        return $features;
    }

    /**
     * @param  list<self>  $enabled
     * @return array<string, bool>
     */
    public static function only(array $enabled): array
    {
        $features = self::noneEnabled();

        foreach ($enabled as $feature) {
            $features[$feature->value] = true;
        }

        return $features;
    }
}
