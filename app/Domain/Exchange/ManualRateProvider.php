<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\OrganizationSetting;
use Carbon\CarbonImmutable;

final class ManualRateProvider implements ExchangeRateProvider
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws ExchangeRateUnavailableException
     */
    public function fetch(RateSource $source): RateSnapshot
    {
        if (! $this->supports($source)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'este proveedor solo resuelve la tasa manual del negocio',
            );
        }

        if (! $this->context->hasOrganization()) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'la tasa manual es de una organización concreta y no hay ninguna fijada',
            );
        }

        $setting = OrganizationSetting::query()
            ->where('key', SettingKey::ExchangeRateManual->value)
            ->first();

        $rate = $setting?->value;

        if (! is_string($rate) && ! is_int($rate) && ! is_float($rate)) {
            throw ExchangeRateUnavailableException::unreachable(
                $source,
                'la organización tiene la fuente manual seleccionada y no ha escrito ninguna tasa',
            );
        }

        $editedAt = $setting->updated_at !== null
            ? CarbonImmutable::parse($setting->updated_at)
            : CarbonImmutable::now();

        return new RateSnapshot(
            rate: is_string($rate) ? $rate : sprintf('%.8F', $rate),
            source: RateSource::Manual,
            publishedAt: $editedAt,
            fetchedAt: $editedAt,
        );
    }

    public function supports(RateSource $source): bool
    {
        return $source === RateSource::Manual;
    }
}
