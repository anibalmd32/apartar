<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Customer\TermsConsent;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La ficha de cliente contra la organización vecina (`REQ-IDN-001` RB-1, SP-4,
 * `REQ-QAX-008` RB-2).
 *
 * **Es el test de fuga de `T-043`**, y lo que protege es la decisión `Q1`: no
 * hay registro global de personas. La misma cédula en dos negocios son dos
 * fichas con su propio contacto, y ninguna sabe de la otra.
 *
 * La fuga que importa aquí no es una lectura, es **una escritura**: si la
 * búsqueda por documento se escapara de la organización, el segundo negocio
 * no crearía su ficha — reutilizaría la del primero y le **sobrescribiría el
 * teléfono**, y los avisos del primero le llegarían a otro número.
 */
final class CustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_document_in_two_organizations_creates_two_isolated_customers(): void
    {
        $ferreteria = Organization::factory()->create();
        $bodega = $this->neighbourOrganization();

        $ours = $this->withinOrganization($ferreteria, fn (): Customer => $this->resolve('0414-1234567', 'maria@ferreteria.test'));
        $theirs = $this->withinOrganization($bodega, fn (): Customer => $this->resolve('0424-7654321', null));

        $this->assertNotSame($ours->getKey(), $theirs->getKey());

        // La primera ficha sigue con su contacto: la segunda no la reutilizó.
        $row = DB::table('customers')->where('id', $ours->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('+584141234567', $row->phone);
        $this->assertSame('maria@ferreteria.test', $row->email);
        $this->assertSame((int) $ferreteria->getKey(), (int) $row->organization_id);

        // Crear en el vecino no es sobrescribir aquí: ninguna auditoría en
        // ninguna de las dos.
        $this->assertSame(0, DB::table('activity_log')->count());

        // Y cada una ve la suya, y solo la suya.
        $this->assertSame([$ours->getKey()], $this->withinOrganization($ferreteria, fn (): array => Customer::query()->pluck('id')->all()));
        $this->assertSame([$theirs->getKey()], $this->withinOrganization($bodega, fn (): array => Customer::query()->pluck('id')->all()));
    }

    /**
     * La reutilización de un negocio no toca la ficha del otro, y su auditoría
     * queda en el negocio que la hizo.
     */
    public function test_reusing_in_one_organization_leaves_the_other_untouched(): void
    {
        $ferreteria = Organization::factory()->create();
        $bodega = $this->neighbourOrganization();

        $this->withinOrganization($ferreteria, fn (): Customer => $this->resolve('0414-1234567', null));
        $theirs = $this->withinOrganization($bodega, fn (): Customer => $this->resolve('0414-1234567', null));

        $this->withinOrganization($ferreteria, fn (): Customer => $this->resolve('0416-0000000', null));

        $this->assertSame('+584141234567', DB::table('customers')->where('id', $theirs->getKey())->value('phone'));

        $this->assertSame(1, $this->withinOrganization($ferreteria, fn (): int => AuditEntry::query()->count()));
        $this->assertSame(0, $this->withinOrganization($bodega, fn (): int => AuditEntry::query()->count()));
    }

    private function resolve(string $phone, ?string $email): Customer
    {
        return app(ResolveCustomerAction::class)->handle(
            CustomerContactData::fromInput('V-12.345.678', 'María González', $phone, $email),
            TermsConsent::acceptedNowFrom('190.202.10.5'),
        );
    }
}
