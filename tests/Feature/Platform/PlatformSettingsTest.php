<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Domain\Payment\ReceiptFile;
use App\Domain\Platform\PlatformSettingKey;
use App\Domain\Subscription\DowngradeOutcome;
use App\Domain\Telemetry\TelemetryRetention;
use App\Models\ExportJob;
use App\Models\PlatformSetting;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Tests\TestCase;

final class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_rows_every_key_reads_its_default(): void
    {
        foreach (PlatformSettingKey::cases() as $key) {
            $this->assertSame($key->default(), PlatformSettings::get($key), $key->value);
        }
    }

    public function test_the_consumers_read_the_setting(): void
    {
        PlatformSettings::set(PlatformSettingKey::ReceiptMaxSizeMb, 8);
        PlatformSettings::set(PlatformSettingKey::DowngradeGraceDays, 10);
        PlatformSettings::set(PlatformSettingKey::TelemetryRetentionDaysDefault, 90);
        PlatformSettings::set(PlatformSettingKey::ExportLinkTtlHours, 2);

        $this->assertSame(8, ReceiptFile::maxSizeMb());
        $this->assertSame('2026-11-11 00:00:00', DowngradeOutcome::unpublishDateFor(CarbonImmutable::parse('2026-11-01 00:00:00'))->format('Y-m-d H:i:s'));
        $this->assertSame(90, TelemetryRetention::platformDefaultDays());

        $url = (new ExportJob)->forceFill(['id' => 7])->downloadUrl();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), (int) $query['expires'], 5);
        $this->assertTrue(URL::hasValidSignature(request()->create($url)));
    }

    public function test_out_of_range_values_never_take_effect(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            PlatformSetting::query()->create(['key' => PlatformSettingKey::SubscriptionGraceDays->value, 'value' => 0]);
            PlatformSettings::forget();
            $this->assertSame(7, PlatformSettings::get(PlatformSettingKey::SubscriptionGraceDays));
        } finally {
            PlatformSettings::set(PlatformSettingKey::SubscriptionGraceDays, 0);
        }
    }
}
