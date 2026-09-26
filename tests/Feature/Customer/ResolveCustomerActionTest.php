<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Actions\Customer\CustomerContactData;
use App\Actions\Customer\ResolveCustomerAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Customer\TermsConsent;
use App\Domain\Tenancy\MissingTenantContextException;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Support\DocumentKind;
use App\Support\InvalidPhoneNumberException;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * La ficha del cliente final (`REQ-IDN-001`), contra la base de datos.
 *
 * La acción no tiene quien la llame hasta `T-045` —la ficha nace dentro de la
 * transacción del apartado (RB-6) y no hay endpoint que la cree—, así que se
 * la llama directamente, que es lo que hará `CreateLayawayAction`. El
 * aislamiento entre organizaciones (RB-1, SP-4) está en
 * `tests/Feature/Tenancy/CustomerIsolationTest`, y la carrera de SP-5, que
 * necesita dos conexiones, en `tests/Feature/Concurrency`.
 */
final class ResolveCustomerActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();

        $this->actingAsOrganization($this->organization);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Crear
    // -------------------------------------------------------------------

    public function test_a_new_document_creates_the_customer_with_its_consent(): void
    {
        CarbonImmutable::setTestNow('2026-09-26 10:15:00');

        $customer = $this->resolve($this->contact(), $this->consent('190.202.10.5'));

        $row = DB::table('customers')->where('id', $customer->getKey())->first();

        $this->assertNotNull($row);
        $this->assertSame((int) $this->organization->getKey(), (int) $row->organization_id);
        $this->assertSame('V12345678', $row->document_number);
        $this->assertSame('cedula', $row->document_kind);
        $this->assertSame('María González', $row->full_name);
        $this->assertSame('+584141234567', $row->phone);
        $this->assertSame('maria@ejemplo.test', $row->email);
        $this->assertSame('2026-09-26 10:15:00', $row->terms_accepted_at);
        $this->assertSame('190.202.10.5', $row->terms_ip_address);

        // Crear no es sobrescribir: no hay antes que registrar.
        $this->assertSame(0, AuditEntry::query()->count());
    }

    /**
     * RB-7 · un negocio aparta como cliente de otro, caso habitual en el
     * mayorista.
     */
    public function test_a_rif_is_accepted_as_well_as_a_cedula(): void
    {
        $customer = $this->resolve($this->contact(document: 'j-12345678-9', name: 'Inversiones El Tornillo C.A.'));

        $this->assertSame('J123456789', $customer->document_number);
        $this->assertSame(DocumentKind::Rif, $customer->fresh()?->document_kind);
    }

    /**
     * RB-3, SP-7 · sin correo es una ficha normal, no una degradada.
     */
    public function test_the_email_is_optional(): void
    {
        $customer = $this->resolve($this->contact(email: ''));

        $this->assertNull($customer->email);
        $this->assertNull(DB::table('customers')->where('id', $customer->getKey())->value('email'));
    }

    /**
     * RB-3 · el caso familiar: dos personas del mismo negocio con un correo.
     */
    public function test_two_customers_of_the_same_business_can_share_an_email(): void
    {
        $mother = $this->resolve($this->contact(document: 'V10000001', name: 'Ana Pérez', email: 'casa@ejemplo.test'));
        $son = $this->resolve($this->contact(document: 'V30000002', name: 'Luis Pérez', email: 'casa@ejemplo.test'));

        $this->assertNotSame($mother->getKey(), $son->getKey());
        $this->assertSame(2, Customer::query()->where('email', 'casa@ejemplo.test')->count());
    }

    /**
     * RB-2, SP-2 · el teléfono no se puede omitir: ni vacío, ni nulo.
     */
    public function test_the_phone_is_mandatory(): void
    {
        try {
            CustomerContactData::fromInput('V12345678', 'María González', '', null);
            $this->fail('Se construyó un contacto sin teléfono.');
        } catch (InvalidPhoneNumberException) {
        }

        $phone = (new ReflectionMethod(CustomerContactData::class, '__construct'))->getParameters()[2];
        $type = $phone->getType();

        $this->assertSame('phone', $phone->getName());
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(PhoneNumber::class, $type->getName());
        $this->assertFalse($type->allowsNull(), 'El teléfono del contacto admite null: RB-2 dice que es obligatorio.');

        $this->assertSame(0, Customer::query()->count());
    }

    // -------------------------------------------------------------------
    // Reutilizar
    // -------------------------------------------------------------------

    /**
     * RB-4 con la clave canónica de `T-040`: `12.345.678` y `V12345678` son el
     * mismo documento y encuentran la misma ficha.
     */
    public function test_the_document_is_normalised_before_looking_it_up(): void
    {
        $first = $this->resolve($this->contact(document: 'V12345678'));
        $again = $this->resolve($this->contact(document: '12.345.678'));

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame(1, Customer::query()->count());
    }

    /**
     * RB-4 · el cliente que cambió de número: se reutiliza la ficha, se
     * sobrescribe el contacto y queda `customer.contact_updated` con el antes y
     * el después **de verdad**, sin redactar, y la IP desde la que se hizo.
     */
    public function test_reusing_the_customer_overwrites_the_contact_and_audits_it(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 09:00:00');
        $first = $this->resolve($this->contact(), $this->consent('190.202.10.5'));

        CarbonImmutable::setTestNow('2026-09-26 18:30:00');
        $again = $this->resolve(
            $this->contact(name: 'María González', phone: '0424-7654321', email: 'maria.nueva@ejemplo.test'),
            $this->consent('186.14.0.9'),
        );

        $this->assertSame($first->getKey(), $again->getKey());

        $row = DB::table('customers')->where('id', $first->getKey())->first();
        $this->assertNotNull($row);
        $this->assertSame('+584247654321', $row->phone);
        $this->assertSame('maria.nueva@ejemplo.test', $row->email);

        $entry = $this->onlyEntryOf(AuditEvent::CustomerContactUpdated);

        $this->assertSame($first->getKey(), $entry->subject_id);
        $this->assertNull($entry->causer_id, 'La sobrescritura la hace un visitante sin sesión, no un miembro.');
        $this->assertStringContainsString('María González', $entry->description);
        $this->assertStringContainsString(ResolveCustomerAction::PROCESS, $entry->description);

        $changed = $this->changedIn($entry);

        $this->assertEquals(['before' => '+584141234567', 'after' => '+584247654321'], $changed['phone']);
        $this->assertEquals(['before' => 'maria@ejemplo.test', 'after' => 'maria.nueva@ejemplo.test'], $changed['email']);
        $this->assertEquals(['before' => '190.202.10.5', 'after' => '186.14.0.9'], $changed['terms_ip_address']);
        $this->assertArrayNotHasKey('full_name', $changed, 'El nombre no cambió y aparece en el diff.');

        // Un cambio ordinario no es un conflicto de identidad.
        $this->assertSame(0, $this->entriesOf(AuditEvent::CustomerIdentityConflict));
    }

    /**
     * **Ausente no es vacío** (corrección de RB-4, `T-045`): un correo omitido
     * no borra el almacenado. Borrarlo le quitaría al cliente el enlace de
     * acceso de `REQ-IDN-003`, que pide documento y correo, sin que él lo
     * hubiera pedido. Tampoco aparece en el diff: no cambió.
     */
    #[DataProvider('omittedEmails')]
    public function test_a_returning_customer_without_email_keeps_the_stored_one(?string $omitted): void
    {
        $customer = $this->resolve($this->contact(email: 'maria@ejemplo.test'));
        $this->resolve($this->contact(phone: '0424-7654321', email: $omitted));

        $this->assertSame('maria@ejemplo.test', $customer->fresh()?->email);
        $this->assertArrayNotHasKey('email', $this->changedIn($this->onlyEntryOf(AuditEvent::CustomerContactUpdated)));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function omittedEmails(): array
    {
        return ['nulo' => [null], 'en blanco' => ['']];
    }

    /**
     * Y un correo nuevo sí sustituye al anterior: lo que no se hace es borrar.
     */
    public function test_a_returning_customer_with_a_new_email_replaces_it(): void
    {
        $customer = $this->resolve($this->contact(email: 'maria@ejemplo.test'));
        $this->resolve($this->contact(email: 'maria.nueva@ejemplo.test'));

        $this->assertSame('maria.nueva@ejemplo.test', $customer->fresh()?->email);
    }

    /**
     * SP-3 · un nombre que no se parece en nada: la venta sigue, la ficha se
     * actualiza, y **además** de la sobrescritura sale la alarma.
     */
    public function test_a_radically_different_name_raises_an_identity_conflict_without_blocking(): void
    {
        $first = $this->resolve($this->contact(name: 'María González'));
        $again = $this->resolve($this->contact(name: 'Pedro Ramírez'));

        $this->assertSame($first->getKey(), $again->getKey());
        $this->assertSame('Pedro Ramírez', $first->fresh()?->full_name);

        $conflict = $this->onlyEntryOf(AuditEvent::CustomerIdentityConflict);

        $this->assertEquals(
            ['full_name' => ['before' => 'María González', 'after' => 'Pedro Ramírez']],
            $this->changedIn($conflict),
        );

        $this->assertEquals(
            ['before' => 'María González', 'after' => 'Pedro Ramírez'],
            $this->changedIn($this->onlyEntryOf(AuditEvent::CustomerContactUpdated))['full_name'],
        );
    }

    /**
     * El conflicto tiene que ser raro: una tilde, un segundo apellido o una
     * errata son la misma persona escribiendo con prisa.
     */
    public function test_an_ordinary_name_change_is_not_an_identity_conflict(): void
    {
        $this->resolve($this->contact(name: 'María González'));
        $this->resolve($this->contact(name: 'Maria Gonzales Pérez'));

        $this->assertSame(1, $this->entriesOf(AuditEvent::CustomerContactUpdated));
        $this->assertSame(0, $this->entriesOf(AuditEvent::CustomerIdentityConflict));
    }

    /**
     * `REQ-IDN-005` RB-3 · cada apartado renueva el consentimiento, y la
     * aceptación anterior **no se pierde**: es el antes de la entrada, aunque
     * el contacto venga idéntico.
     */
    public function test_a_returning_customer_renews_the_consent_and_the_previous_one_stays_in_the_audit(): void
    {
        CarbonImmutable::setTestNow('2026-09-20 09:00:00');
        $customer = $this->resolve($this->contact(), $this->consent('190.202.10.5'));

        CarbonImmutable::setTestNow('2026-09-26 18:30:00');
        $this->resolve($this->contact(), $this->consent('190.202.10.5'));

        $fresh = $customer->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('2026-09-26 18:30:00', $fresh->terms_accepted_at?->format('Y-m-d H:i:s'));

        $changed = $this->changedIn($this->onlyEntryOf(AuditEvent::CustomerContactUpdated));

        $this->assertSame(['terms_accepted_at'], array_keys($changed));
        $this->assertStringStartsWith('2026-09-20T09:00:00', (string) $changed['terms_accepted_at']['before']);
        $this->assertStringStartsWith('2026-09-26T18:30:00', (string) $changed['terms_accepted_at']['after']);
    }

    /**
     * Y si de verdad no cambió nada —mismo contacto, mismo instante, misma IP—
     * no hay nada que registrar.
     */
    public function test_an_identical_resubmission_writes_no_entry(): void
    {
        CarbonImmutable::setTestNow('2026-09-26 18:30:00');

        $this->resolve($this->contact(), $this->consent('190.202.10.5'));
        $this->resolve($this->contact(), $this->consent('190.202.10.5'));

        $this->assertSame(0, AuditEntry::query()->count());
    }

    // -------------------------------------------------------------------
    // La evidencia de consentimiento no se puede perder
    // -------------------------------------------------------------------

    /**
     * Primera capa: la acción no se deja llamar sin evidencia, en ninguno de
     * sus dos caminos, porque los dos pasan por el mismo parámetro.
     */
    public function test_the_action_cannot_be_called_without_consent(): void
    {
        $consent = (new ReflectionMethod(ResolveCustomerAction::class, 'handle'))->getParameters()[1];
        $type = $consent->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(TermsConsent::class, $type->getName());
        $this->assertFalse($type->allowsNull());
        $this->assertFalse($consent->isOptional());
    }

    /**
     * Segunda capa: lo que no pase por la acción tampoco crea una ficha sin
     * evidencia, ni se la borra a una que la tiene.
     */
    public function test_the_model_refuses_a_customer_without_consent(): void
    {
        $customer = new Customer;
        $customer->forceFill([
            'document_number' => 'V12345678',
            'document_kind' => DocumentKind::Cedula,
            'full_name' => 'Sin Consentimiento',
            'phone' => '+584141234567',
        ]);

        try {
            $customer->save();
            $this->fail('Se creó una ficha sin evidencia de consentimiento.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('terms_accepted_at', $exception->getMessage());
        }

        $existing = $this->resolve($this->contact());

        foreach (Customer::CONSENT_COLUMNS as $column) {
            try {
                $existing->fresh()?->forceFill([$column => null])->save();
                $this->fail("Se borró {$column} de una ficha.");
            } catch (LogicException $exception) {
                $this->assertStringContainsString($column, $exception->getMessage());
            }
        }

        $this->assertNotNull(DB::table('customers')->where('id', $existing->getKey())->value('terms_ip_address'));
        $this->assertSame(1, Customer::query()->count());
    }

    // -------------------------------------------------------------------
    // Sin tenant
    // -------------------------------------------------------------------

    /**
     * SP-8 · jamás se crea una ficha sin organización. Lo impide el global
     * scope al leer, antes de que nada se escriba.
     */
    public function test_without_a_tenant_it_throws_and_writes_nothing(): void
    {
        $this->withoutOrganization();

        try {
            $this->resolve($this->contact());
            $this->fail('Se resolvió una ficha sin organización fijada.');
        } catch (MissingTenantContextException) {
        }

        $this->assertSame(0, DB::table('customers')->count());
    }

    // -------------------------------------------------------------------

    private function resolve(CustomerContactData $contact, ?TermsConsent $consent = null): Customer
    {
        return app(ResolveCustomerAction::class)->handle($contact, $consent ?? $this->consent());
    }

    private function contact(
        string $document = 'V12345678',
        string $name = 'María González',
        string $phone = '0414-1234567',
        ?string $email = 'maria@ejemplo.test',
    ): CustomerContactData {
        return CustomerContactData::fromInput($document, $name, $phone, $email);
    }

    private function consent(string $ip = '190.202.10.5'): TermsConsent
    {
        return TermsConsent::acceptedNowFrom($ip);
    }

    private function onlyEntryOf(AuditEvent $event): AuditEntry
    {
        $entries = AuditEntry::query()->audited()->ofEvent($event)->get();

        $this->assertCount(1, $entries, "Se esperaba exactamente una entrada {$event->value}.");

        $entry = $entries->first();
        $this->assertInstanceOf(AuditEntry::class, $entry);

        return $entry;
    }

    private function entriesOf(AuditEvent $event): int
    {
        return AuditEntry::query()->audited()->ofEvent($event)->count();
    }

    /**
     * El diff de la entrada. La columna es `JSON` de MySQL, que **reordena las
     * claves** de cada objeto, así que los pares se comparan con
     * `assertEquals` y no con `assertSame`.
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    private function changedIn(AuditEntry $entry): array
    {
        /** @var array<string, array{before: mixed, after: mixed}> $changed */
        $changed = $entry->properties?->toArray()['changed'] ?? [];

        return $changed;
    }
}
