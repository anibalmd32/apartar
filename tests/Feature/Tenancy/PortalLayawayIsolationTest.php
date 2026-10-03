<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El detalle del portal contra la organización vecina (`REQ-QAX-008` RB-2,
 * `REQ-LAY-021` RB-1, `REQ-ACC-005` SP-6).
 *
 * La misma persona con ficha en dos negocios, entrando por el código de uno:
 * el apartado del otro **no existe** para su sesión, aunque el documento sea
 * el mismo. Es el `404` que sostiene la excepción `{code}` de
 * `CrossTenantAccessTest`.
 */
final class PortalLayawayIsolationTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    public function test_the_layaway_of_the_same_person_in_another_business_is_not_found(): void
    {
        $ours = $this->subscribe(Organization::factory()->create());
        $theirs = $this->neighbourOrganization();
        $mine = $this->layawayIn($ours);
        $neighbours = $this->layawayIn($theirs);
        DB::table('customers')->update(['document_number' => 'V12345678']);

        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($mine->customer_id));
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer');

        $this->get(route('portal.layaways.show', ['code' => $mine->code]))->assertOk();
        $this->get(route('portal.layaways.show', ['code' => $neighbours->code]))->assertNotFound();
    }
}
