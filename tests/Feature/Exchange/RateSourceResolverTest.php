<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Domain\Exchange\RateSource;
use App\Domain\Exchange\RateSourceResolver;
use App\Domain\Settings\SettingKey;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Product;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class RateSourceResolverTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_el_producto_manda_sobre_el_catalogo_y_el_catalogo_sobre_el_negocio(): void
    {
        $organization = Organization::factory()->create();
        $this->businessUses($organization, RateSource::Parallel);

        [$plain, $own, $catalog, $loose] = app(TenantContext::class)->runFor($organization, fn (): array => [
            Product::factory()->create(),
            Product::factory()->create(['rate_source' => RateSource::Manual]),
            Catalog::factory()->create(['rate_source' => RateSource::Bcv]),
            Catalog::factory()->create(),
        ]);

        $this->assertSame(RateSource::Parallel, $this->resolver()->forProduct($organization, $plain, null));
        $this->assertSame(RateSource::Parallel, $this->resolver()->forProduct($organization, $plain, $loose));
        $this->assertSame(RateSource::Bcv, $this->resolver()->forProduct($organization, $plain, $catalog));
        $this->assertSame(RateSource::Manual, $this->resolver()->forProduct($organization, $own, $catalog));
        $this->assertNull(RateSourceResolver::override($plain, $loose));
        $this->assertSame(RateSource::Bcv, RateSourceResolver::override($plain, $catalog));
    }

    public function test_un_apartado_usa_la_tasa_de_su_producto_si_no_la_de_su_catalogo_y_si_no_la_del_negocio(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());

        [$product, $catalog] = app(TenantContext::class)->runFor($organization, fn (): array => [
            Product::factory()->create(['stock' => 5]),
            Catalog::factory()->create(['rate_source' => RateSource::Parallel]),
        ]);

        $layaway = $this->layawayIn($organization, product: $product);

        $this->assertSame(RateSource::Bcv, $this->resolver()->forLayaway($layaway));
        $this->assertNull($this->resolver()->overrideForLayaway($layaway));

        DB::table('layaways')->where('id', $layaway->getKey())->update(['catalog_id' => $catalog->getKey()]);
        $layaway->refresh();

        $this->assertSame(RateSource::Parallel, $this->resolver()->forLayaway($layaway));

        DB::table('products')->where('id', $product->getKey())->update(['rate_source' => RateSource::Bcv->value]);

        $this->assertSame(RateSource::Bcv, $this->resolver()->forLayaway($layaway));
        $this->assertSame(RateSource::Bcv, $this->resolver()->overrideForLayaway($layaway));
    }

    public function test_lee_el_producto_aunque_este_archivado_y_fuera_del_negocio_activo(): void
    {
        $organization = $this->subscribe(Organization::factory()->create());
        $product = app(TenantContext::class)->runFor($organization, fn (): Product => Product::factory()->create(['stock' => 5, 'rate_source' => RateSource::Parallel]));
        $layaway = $this->layawayIn($organization, product: $product);

        DB::table('products')->where('id', $product->getKey())->update(['deleted_at' => now()]);
        app(TenantContext::class)->forget();

        $this->assertSame(RateSource::Parallel, $this->resolver()->forLayaway($layaway));
    }

    private function resolver(): RateSourceResolver
    {
        return app(RateSourceResolver::class);
    }

    private function businessUses(Organization $organization, RateSource $source): void
    {
        app(TenantContext::class)->runFor($organization, fn () => OrganizationSetting::query()->updateOrCreate(
            ['key' => SettingKey::ExchangeRateSource->value],
            ['value' => $source->value],
        ));
        app(Settings::class)->forget($organization);
    }
}
