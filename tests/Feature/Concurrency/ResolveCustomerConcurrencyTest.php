<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\TermsConsent;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `REQ-IDN-001` SP-5: dos apartados simultáneos con el mismo documento nuevo.
 *
 * Las dos peticiones leen, las dos ven que la ficha no existe y las dos
 * insertan. `UNIQUE(organization_id, document_number)` deja pasar a una; a la
 * otra le devuelve una colisión que la acción captura para **releer una vez**
 * y reutilizar la ficha que ganó. El resultado tiene que ser **una** ficha y
 * un apartado que no se cae.
 *
 * ## Lo que de verdad se prueba: que la relectura bloquea
 *
 * La trampa está en la relectura. Dentro de `CreateLayawayAction` todo ocurre
 * en una transacción, y en `REPEATABLE READ` una lectura normal ve **la foto
 * tomada en la primera lectura** de esa transacción — anterior a que la otra
 * confirmase. Releer sin bloqueo devolvería «no existe» otra vez, justo
 * después de que el índice dijera que sí. Solo un `FOR UPDATE` lee la última
 * versión confirmada.
 *
 * **Cómo se provoca sin dos procesos**, como `LayawayStateMachineConcurrencyTest`:
 * con dos conexiones y el orden forzado a mano.
 *
 *  1. nuestra transacción abre y lee: el documento no existe, y esa lectura
 *     fija su foto — es la lectura que hace la propia acción;
 *  2. la rival inserta la misma ficha y confirma, que es la otra petición
 *     ganando la carrera;
 *  3. nuestra acción, dentro de la misma transacción, vuelve a leer con la
 *     foto vieja, inserta, colisiona, relee con bloqueo y reutiliza.
 *
 * Si la relectura no bloqueara, el paso 3 lanzaría la colisión en lugar de
 * devolver la ficha — se comprobó quitando el `lockForUpdate()`.
 *
 * **Sin `RefreshDatabase` y en esta carpeta**: la colisión solo existe contra
 * una fila confirmada, y la segunda conexión no vería nada dentro de la
 * transacción de `RefreshDatabase`. Este archivo barre lo suyo.
 */
final class ResolveCustomerConcurrencyTest extends TestCase
{
    private const SLUG_PREFIX = 'concurrencia-cliente-';

    private const DOCUMENT = 'V23456789';

    private ?Connection $rival = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('customers')) {
            $this->artisan('migrate', ['--force' => true]);
        }

        $this->purgeFixtures();
    }

    protected function tearDown(): void
    {
        $this->releaseRival();

        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $this->withoutOrganization();
        $this->purgeFixtures();

        parent::tearDown();
    }

    public function test_two_simultaneous_requests_with_the_same_new_document_leave_one_customer(): void
    {
        $organization = $this->seedOrganizations();

        $winnerId = $this->withinOrganization($organization, function () use ($organization): int {
            DB::beginTransaction();

            // (1) Nuestra petición lee: no existe. Aquí se fija la foto.
            $this->assertFalse(Customer::query()->where('document_number', self::DOCUMENT)->exists());

            // (2) La otra petición gana la carrera y confirma.
            $rivalId = $this->rivalInserts($organization);

            // (3) La nuestra sigue, dentro de la misma transacción.
            $customer = app(ResolveCustomerAction::class)->handle(
                CustomerContactData::fromInput(self::DOCUMENT, 'Carmen Rodríguez', '0414-1112233', 'carmen@ejemplo.test'),
                TermsConsent::acceptedNowFrom('190.202.10.5'),
            );

            DB::commit();

            $this->assertSame($rivalId, $customer->getKey(), 'La acción no reutilizó la ficha que ganó la carrera.');

            return $rivalId;
        });

        $rows = DB::table('customers')
            ->where('organization_id', $organization->getKey())
            ->where('document_number', self::DOCUMENT)
            ->get();

        $this->assertCount(1, $rows);

        // Y lo que perdió la carrera no se perdió: su contacto es el que queda,
        // con la sobrescritura registrada como cualquier otra reutilización.
        $this->assertSame($winnerId, (int) $rows[0]->id);
        $this->assertSame('+584141112233', $rows[0]->phone);
        $this->assertSame('190.202.10.5', $rows[0]->terms_ip_address);
        $this->assertSame(1, DB::table('activity_log')
            ->where('organization_id', $organization->getKey())
            ->where('event', AuditEvent::CustomerContactUpdated->value)
            ->where('subject_id', $winnerId)
            ->count());
    }

    /**
     * El control: sin carrera, el mismo documento nuevo se crea sin colisión.
     * Sin este caso, una acción que siempre reutilizara pasaría el de arriba.
     */
    public function test_without_a_race_the_new_document_is_simply_created(): void
    {
        $organization = $this->seedOrganizations();

        $customer = $this->withinOrganization($organization, fn (): Customer => app(ResolveCustomerAction::class)->handle(
            CustomerContactData::fromInput(self::DOCUMENT, 'Carmen Rodríguez', '0414-1112233', null),
            TermsConsent::acceptedNowFrom('190.202.10.5'),
        ));

        $this->assertTrue($customer->wasRecentlyCreated);
        $this->assertSame(0, DB::table('activity_log')->where('organization_id', $organization->getKey())->count());
    }

    // -------------------------------------------------------------------

    /**
     * La organización del test y la vecina, con **su** ficha del mismo
     * documento (`REQ-QAX-003` RB-10): si la búsqueda o la colisión se
     * escaparan de la organización, se vería contra ella.
     */
    private function seedOrganizations(): Organization
    {
        $organization = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);
        $neighbour = Organization::factory()->create(['slug' => self::SLUG_PREFIX.bin2hex(random_bytes(4))]);

        DB::table('customers')->insert($this->row($neighbour, 'Vecina Con El Mismo Documento', '+584160000000'));

        return $organization;
    }

    private function rivalInserts(Organization $organization): int
    {
        $rival = $this->rival();

        $rival->beginTransaction();
        $id = (int) $rival->table('customers')->insertGetId($this->row($organization, 'Carmen Rodríguez', '+584149998877'));
        $rival->commit();

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Organization $organization, string $name, string $phone): array
    {
        return [
            'organization_id' => $organization->getKey(),
            'document_number' => self::DOCUMENT,
            'document_kind' => 'cedula',
            'full_name' => $name,
            'phone' => $phone,
            'terms_accepted_at' => now(),
            'terms_ip_address' => '186.14.0.9',
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
     * Barre lo que siembra, tabla a tabla y en orden (convención de «Cómo leer
     * este plan»): las fichas y sus entradas de auditoría antes que las
     * organizaciones.
     */
    private function purgeFixtures(): void
    {
        $organizations = DB::table('organizations')
            ->where('slug', 'like', self::SLUG_PREFIX.'%')
            ->pluck('id');

        DB::table('customers')->whereIn('organization_id', $organizations)->delete();
        DB::table('activity_log')->whereIn('organization_id', $organizations)->delete();
        DB::table('organizations')->whereIn('id', $organizations)->delete();
    }
}
