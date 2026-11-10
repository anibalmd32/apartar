<?php

declare(strict_types=1);

namespace Tests\Feature\Safeguard;

use App\Domain\Safeguard\SafeguardScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Catalog;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SafeguardScopeTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create());
        $this->layaway = $this->layawayIn($this->organization);
        $this->catalog = $this->tenant(fn (): Catalog => Catalog::factory()->create(['safeguard_enabled' => true]));
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => $this->catalog->getKey()]);
        $this->layaway->refresh();
    }

    public function test_un_apartado_hecho_desde_el_panel_entra_si_su_producto_esta_en_un_catalogo_con_resguardo(): void
    {
        DB::table('layaways')->where('id', $this->layaway->getKey())->update(['catalog_id' => null]);
        $layaway = $this->layaway->refresh();

        $this->assertFalse($this->tenant(fn (): bool => SafeguardScope::appliesTo($layaway)));

        $this->catalog->products()->attach($layaway->product_id, ['position' => 1]);

        $this->assertTrue($this->tenant(fn (): bool => SafeguardScope::appliesTo($layaway)));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function tenant(\Closure $callback): mixed
    {
        return app(TenantContext::class)->runFor($this->organization, $callback);
    }
}
