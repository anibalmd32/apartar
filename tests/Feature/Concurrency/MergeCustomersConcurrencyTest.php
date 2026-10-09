<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Customer\MergeCustomersAction;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * La fusión contra otra transacción de verdad (`REQ-IDN-006` SP-4, `T-065`).
 *
 * Mientras otra operación tiene la ficha absorbida —otra fusión de la misma
 * pareja— o uno de sus apartados —un comprobante a medias—, la fusión
 * **espera**: no mueve nada a medias ni encima de ella. Aquí la espera tiene
 * un techo de un segundo, y agotarlo deja las dos fichas como estaban; cuando
 * el otro suelta, fusiona.
 *
 * **Sin `RefreshDatabase` y en esta carpeta**, como los demás de aquí. Este
 * archivo barre lo suyo, tabla a tabla.
 */
final class MergeCustomersConcurrencyTest extends TestCase
{
    use CreatesLayaways;

    private const SLUG_PREFIX = 'concurrencia-fusion-';

    private ?Connection $rival = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();

        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::statement('SET SESSION innodb_lock_wait_timeout = 50');
        $this->withoutOrganization();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_a_merge_waits_for_the_absorbed_record_and_its_layaways(): void
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $member = $this->withinOrganization($organization, fn (): User => User::factory()->create());
        $survivor = $this->withinOrganization($organization, fn (): Customer => Customer::factory()->create(['document_number' => 'V11111111']));
        $layaway = $this->layawayIn($organization);
        $absorbed = $this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
        $this->withoutOrganization();
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');

        foreach (['select id from customers where id = ? for update' => $absorbed->getKey(), 'select id from layaways where id = ? for update' => $layaway->getKey()] as $lock => $id) {
            $rival = $this->rival();
            $rival->beginTransaction();
            $rival->select($lock, [$id]);

            try {
                $this->merge($organization, $survivor, $absorbed, $member);
                $this->fail("Fusionó sin esperar a «{$lock}».");
            } catch (QueryException $exception) {
                $this->assertStringContainsString('Lock wait timeout', $exception->getMessage());
            }

            $this->assertSame($absorbed->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('customer_id'));
            $this->assertNotNull(DB::table('customers')->where('id', $absorbed->getKey())->first());
            $rival->commit();
        }

        $this->merge($organization, $survivor, $absorbed, $member);

        $this->assertSame($survivor->getKey(), (int) DB::table('layaways')->where('id', $layaway->getKey())->value('customer_id'));
    }

    private function merge(Organization $organization, Customer $survivor, Customer $absorbed, User $member): void
    {
        $this->withinOrganization($organization, fn () => app(MergeCustomersAction::class)->handle($survivor, $absorbed, $member));
        $this->withoutOrganization();
    }

    private function rival(): Connection
    {
        if ($this->rival instanceof Connection) {
            return $this->rival;
        }

        config(['database.connections.mysql_rival' => config('database.connections.mysql')]);

        return $this->rival = DB::connection('mysql_rival');
    }

    private function releaseRival(): void
    {
        if (! $this->rival instanceof Connection) {
            return;
        }

        if ($this->rival->transactionLevel() > 0) {
            $this->rival->rollBack();
        }

        $this->rival->disconnect();
        $this->rival = null;

        DB::purge('mysql_rival');
    }

    /**
     * Barre lo que siembra, tabla a tabla y en orden: lo que apunta a las
     * fichas antes que ellas, porque sus claves son `restrict`.
     */
    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')->where('slug', 'like', self::SLUG_PREFIX.'%')->pluck('id');

        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('customer_merges')->whereIn('organization_id', $organizations)->delete();
        DB::table('installments')->whereIn('layaway_id', DB::table('layaways')->whereIn('organization_id', $organizations)->select('id'))->delete();
        DB::table('layaways')->whereIn('organization_id', $organizations)->delete();
        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('products')->whereIn('organization_id', $organizations)->delete();
        DB::table('users')->whereIn('organization_id', $organizations)->delete();
        DB::table('usage_counters')->whereIn('organization_id', $organizations)->delete();
        DB::table('organization_settings')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
