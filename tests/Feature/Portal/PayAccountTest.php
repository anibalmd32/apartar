<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Models\Account;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PayAccountTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    public function test_a_google_session_lists_its_layaways_to_pay_across_businesses(): void
    {
        $ferreteria = $this->subscribe(Organization::factory()->create(['name' => 'Ferretería Oriente']));
        $boutique = $this->subscribe(Organization::factory()->create(['name' => 'Boutique Sur']));
        $account = Account::factory()->create(['display_name' => 'María Pérez']);
        $tool = $this->layawayIn($ferreteria);
        $dress = $this->layawayIn($boutique);
        $this->linkTo($account, $tool);
        $this->linkTo($account, $dress);
        $this->withoutOrganization();

        $this->actingAs($account, 'account')->get(route('portal.access', ['codigo' => $tool->code]))->assertRedirect(route('portal.pay'));

        $this->actingAs($account, 'account')
            ->get(route('portal.pay'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('portal/Pay')
                ->has('layaways', 2)
                ->where('layaways.0.payUrl', fn (string $url): bool => str_contains($url, '/comprobante')));
    }

    private function linkTo(Account $account, Layaway $layaway): void
    {
        DB::table('customers')->where('id', $layaway->customer_id)->update(['account_id' => $account->getKey()]);
    }
}
