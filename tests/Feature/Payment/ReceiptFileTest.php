<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\OrganizationCapability;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use App\Policies\ReceiptPolicy;
use App\Support\SignedFileUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El archivo del comprobante por sus dos rutas firmadas (`REQ-PAY-002` RB-2,
 * RB-3, RB-9, SP-3, SP-4, SP-6).
 *
 * Todas las peticiones van por HTTP de verdad, con el stack entero de
 * middlewares de cada grupo: sesión, tenant, `signed`, policy. Lo de otra
 * organización —el `404` en lugar del `403` de SP-5— está en
 * `ReceiptFileIsolationTest`.
 *
 * El comprobante de casi todos los casos es una captura PNG del cliente
 * titular, guardada donde la deja la subida: `{organization_id}/{uuid}.png`
 * en el disco `receipts`.
 */
final class ReceiptFileTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    private const CONTENT = "\x89PNG\r\n\x1a\nla captura del pago móvil";

    private Organization $organization;

    private Customer $customer;

    private Receipt $receipt;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Receipt::DISK);

        $this->neighbourOrganization();
        $this->organization = $this->subscribe(Organization::factory()->create());
        [$this->customer, $this->receipt, $this->layaway] = $this->receiptWithFile($this->organization);
        $this->withoutOrganization();
    }

    /**
     * RB-2 y RB-9 · el titular, con su URL firmada, ve su captura en la
     * pestaña: `inline`, con el MIME guardado y sin que nadie la guarde.
     */
    public function test_the_owner_sees_the_file_inline_and_uncached(): void
    {
        $response = $this->actingAs($this->customer, 'customer')->get(SignedFileUrl::forCustomer($this->receipt));

        $this->assertServesTheFile($response);
    }

    /**
     * RB-2 · el equipo de ese negocio, por su ruta y con su firma.
     */
    public function test_a_member_of_the_business_sees_the_file_through_the_backoffice(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $response = $this->actingAs($member)->get(SignedFileUrl::forBackoffice($this->receipt));

        $this->assertServesTheFile($response);
    }

    /**
     * SP-3 · sin firma no hay archivo, aunque la sesión sea la del titular.
     */
    public function test_an_unsigned_url_is_forbidden(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        $this->actingAs($this->customer, 'customer')
            ->get(route(SignedFileUrl::CUSTOMER_ROUTE, ['receipt' => $this->receipt->getKey()]))
            ->assertForbidden();

        $this->actingAs($member)
            ->get(route(SignedFileUrl::BACKOFFICE_ROUTE, ['receipt' => $this->receipt->getKey()]))
            ->assertForbidden();
    }

    /**
     * SP-3 y RB-3 · caducada es `403`, y el borde se afirma con el reloj
     * congelado: en el segundo exacto del TTL todavía vale, uno después ya no.
     */
    public function test_an_expired_customer_url_is_forbidden_right_after_its_ttl(): void
    {
        $this->freezeSecond();
        $url = SignedFileUrl::forCustomer($this->receipt);
        $this->actingAs($this->customer, 'customer');

        $this->travel(SignedFileUrl::CUSTOMER_TTL_MINUTES)->minutes();
        $this->get($url)->assertOk();

        $this->travel(1)->seconds();
        $this->get($url)->assertForbidden();
    }

    /**
     * Lo mismo con los 15 minutos del backoffice: a los 6 —ya caducada para
     * el cliente— sigue abriendo.
     */
    public function test_an_expired_backoffice_url_is_forbidden_right_after_its_ttl(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
        $this->freezeSecond();
        $url = SignedFileUrl::forBackoffice($this->receipt);
        $this->actingAs($member);

        $this->travel(SignedFileUrl::CUSTOMER_TTL_MINUTES + 1)->minutes();
        $this->get($url)->assertOk();

        $this->travel(SignedFileUrl::BACKOFFICE_TTL_MINUTES - SignedFileUrl::CUSTOMER_TTL_MINUTES - 1)->minutes();
        $this->get($url)->assertOk();

        $this->travel(1)->seconds();
        $this->get($url)->assertForbidden();
    }

    /**
     * SP-3 · la firma cubre la ruta entera: cambiar el comprobante de la URL
     * —por otro **del mismo titular**, que la policy dejaría pasar—, alargar
     * la caducidad o tocar la firma es `403`.
     */
    public function test_a_tampered_url_is_forbidden(): void
    {
        $other = $this->receiptFor($this->layaway);
        $this->withoutOrganization();
        $url = SignedFileUrl::forCustomer($this->receipt);
        $this->actingAs($this->customer, 'customer');

        $swapped = str_replace(
            '/comprobantes/'.$this->receipt->getKey().'/',
            '/comprobantes/'.$other->getKey().'/',
            $url,
        );
        $this->assertNotSame($url, $swapped);
        $this->get($swapped)->assertForbidden();

        $expires = (int) $this->queryOf($url)['expires'];
        $this->get(str_replace('expires='.$expires, 'expires='.($expires + 3600), $url))->assertForbidden();

        $signature = $this->queryOf($url)['signature'];
        $this->get(str_replace($signature, strrev($signature), $url))->assertForbidden();

        $this->get($url)->assertOk();
    }

    /**
     * SP-4 · una URL firmada válida del comprobante de **otro cliente del
     * mismo negocio** es `403`: la firma dice que alguien la generó, no para
     * quién. Aquí sí es `403` y no `404`: es el mismo tenant y el comprobante
     * existe para él; lo que falla es la policy.
     */
    public function test_a_valid_url_of_another_customer_of_the_same_business_is_forbidden(): void
    {
        [$stranger] = $this->receiptWithFile($this->organization);
        $this->withoutOrganization();

        $this->actingAs($stranger, 'customer')
            ->get(SignedFileUrl::forCustomer($this->receipt))
            ->assertForbidden();
    }

    /**
     * SP-6 · el archivo que falta es `404` con un error en el log, y el
     * comprobante sigue intacto: es evidencia y no depende de su archivo.
     */
    public function test_a_missing_file_is_not_found_logged_and_leaves_the_receipt_alone(): void
    {
        Storage::disk(Receipt::DISK)->delete($this->receipt->file_path);
        $before = (array) DB::table('receipts')->where('id', $this->receipt->getKey())->first();
        $logger = Log::spy();

        $this->actingAs($this->customer, 'customer')
            ->get(SignedFileUrl::forCustomer($this->receipt))
            ->assertNotFound();

        $logger->shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'receipt.file_missing'
                && $context['receipt_id'] === $this->receipt->getKey()
                && $context['file_path'] === $this->receipt->file_path,
        );
        $this->assertSame($before, (array) DB::table('receipts')->where('id', $this->receipt->getKey())->first());
    }

    /**
     * `REQ-IDN-004` · la del portal exige la sesión del portal: la firma sola
     * no abre nada, y quien llega sin sesión va al acceso.
     */
    public function test_the_portal_route_requires_a_portal_session(): void
    {
        $this->get(SignedFileUrl::forCustomer($this->receipt))->assertRedirect(route('portal.access'));

        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();

        // La sesión del backoffice no es la del portal (`REQ-IDN-004` RB-1).
        $this->actingAs($member)->get(SignedFileUrl::forCustomer($this->receipt))->assertRedirect(route('portal.access'));
    }

    /**
     * Y el cliente no entra por la del backoffice aunque tenga su URL: no hay
     * sesión de equipo, así que va al login del backoffice.
     *
     * `actingAs()` con guard lo deja además como guard por defecto, cosa que
     * en una petición real no pasa —el de por defecto es `web`, que es el que
     * mira `auth`—; se devuelve a su sitio para que el caso sea el real. Aun
     * sin devolverlo, el controlador lee `web` y no la ficha: sería `403`.
     */
    public function test_the_backoffice_route_requires_a_team_session(): void
    {
        $default = Auth::getDefaultDriver();
        $this->actingAs($this->customer, 'customer');
        Auth::shouldUse($default);

        $this->get(SignedFileUrl::forBackoffice($this->receipt))->assertRedirect(route('login'));
    }

    /**
     * `REQ-ORG-008` RB-3 · con la organización suspendida el equipo sigue
     * viendo los comprobantes: sin verlos no se aprueban.
     */
    public function test_the_backoffice_route_survives_the_suspension(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());
        $this->withoutOrganization();
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->assertServesTheFile($this->actingAs($member)->get(SignedFileUrl::forBackoffice($this->receipt)));
    }

    /**
     * `ReceiptPolicy` · el equipo pasa por la capacidad `Receipts` de su rol,
     * no por ser del negocio.
     *
     * Hoy los dos roles la tienen (`REQ-ORG-003` RB-1: conciliar es operación
     * diaria), así que «un usuario sin la capacidad» no se puede construir: el
     * rol es un enum cerrado y no hay a quién quitársela. Lo que se afirma es
     * que la policy **sigue** a la capacidad rol a rol, de modo que el día que
     * exista un rol sin ella este caso lo cubra solo. Y lo ajeno, que una
     * llamada fuera de ruta —un job— tampoco cruza.
     */
    public function test_the_policy_follows_the_receipts_capability_of_each_role(): void
    {
        $policy = new ReceiptPolicy;

        foreach (OrganizationRole::cases() as $role) {
            $user = $this->withinOrganization($this->organization, fn (): User => User::factory()->create(['role' => $role]));

            $this->assertSame($role->can(OrganizationCapability::Receipts), $policy->view($user, $this->receipt), $role->value);
        }

        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());

        $this->assertFalse($policy->view($foreign, $this->receipt));
        $this->assertTrue($policy->view($this->customer, $this->receipt));
    }

    /**
     * Un cliente con un comprobante cuyo archivo existe en el disco, donde lo
     * deja la subida.
     *
     * @return array{0: Customer, 1: Receipt, 2: Layaway}
     */
    private function receiptWithFile(Organization $organization): array
    {
        $layaway = $this->layawayIn($organization);
        $path = $organization->getKey().'/'.Str::uuid().'.png';
        Storage::disk(Receipt::DISK)->put($path, self::CONTENT);

        $receipt = $this->receiptFor($layaway, ReceiptStatus::PendingReview, [
            'file_path' => $path,
            'file_mime' => 'image/png',
            'file_size' => strlen(self::CONTENT),
        ]);
        $customer = $this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));

        return [$customer, $receipt, $layaway];
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>  $response
     */
    private function assertServesTheFile(TestResponse $response): void
    {
        $response->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->assertSame(self::CONTENT, $response->streamedContent());
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }
}
