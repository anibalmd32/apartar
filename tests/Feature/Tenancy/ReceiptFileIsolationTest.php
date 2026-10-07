<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Payment\ReceiptStatus;
use App\Http\Middleware\ResolvePortalTenant;
use App\Http\Middleware\ResolveTenant;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SignedFileUrl;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\TestCase;

/**
 * El archivo del comprobante contra la organización vecina (`REQ-PAY-002`
 * RB-2, SP-5; `REQ-ORG-002` RB-6; `REQ-QAX-008` RB-2).
 *
 * SP-5 pide que una URL firmada **válida** abierta desde otro negocio no
 * sirva el archivo. Aquí no sirve y además responde `404`, no `403`: el
 * binding va filtrado por el tenant de quien pide, así que el comprobante
 * ajeno no existe para él, y un `403` confirmaría que existe. Es más estricto
 * que SP-5 y es lo que RB-6 manda para todo recurso ajeno.
 *
 * `CrossTenantAccessTest` ya genera el caso sin firma para las dos rutas. Este
 * añade lo que aquel no puede: la firma **buena**, que es la que SP-5 pone a
 * prueba, y el orden de los middlewares que hace que la respuesta sea `404`.
 */
final class ReceiptFileIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, RefreshDatabase;

    /**
     * SP-5 por el backoffice: el usuario del vecino, con la URL firmada de un
     * comprobante nuestro, recibe `404` —y nunca el archivo ni un `403`—.
     */
    public function test_a_valid_backoffice_url_opened_from_another_business_is_not_found(): void
    {
        [$receipt] = $this->ourReceipt();
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $response = $this->actingAs($intruder)->get(SignedFileUrl::forBackoffice($receipt));

        $response->assertNotFound();
        $this->assertNotSame(403, $response->status());
    }

    /**
     * SP-5 por el portal: la misma persona con ficha en el negocio vecino
     * —mismo documento— y la URL firmada de su comprobante de aquí. Su sesión
     * es la de la otra ficha, el tenant es el de la otra ficha, y el
     * comprobante no existe para ella.
     */
    public function test_a_valid_portal_url_opened_by_a_customer_of_another_business_is_not_found(): void
    {
        [$receipt, $customer] = $this->ourReceipt();
        $theirs = $this->subscribe($this->neighbourOrganization());
        $elsewhere = $this->layawayIn($theirs);
        DB::table('customers')->where('id', $elsewhere->customer_id)->update(['document_number' => $customer->document_number]);
        $sameDocument = $this->withinOrganization($theirs, fn (): Customer => Customer::query()->findOrFail($elsewhere->customer_id));
        $this->withoutOrganization();

        $response = $this->actingAs($sameDocument, 'customer')->get(SignedFileUrl::forCustomer($receipt));

        $response->assertNotFound();
        $this->assertNotSame(403, $response->status());

        // Y la URL es buena: su titular, con ella, ve el archivo. Entre dos
        // peticiones del mismo test el tenant de la anterior sigue fijado.
        $this->withoutOrganization();
        $this->actingAs($customer, 'customer')->get(SignedFileUrl::forCustomer($receipt))->assertOk();
    }

    /**
     * El orden que hace que lo ajeno sea `404` y no `403`: el tenant se fija
     * antes del binding, y la firma se comprueba **después**. Si `signed`
     * corriera antes, el comprobante de otro negocio sin firma daría `403` y
     * confirmaría que existe. `signed` no está en la lista de prioridades del
     * router, y este test es el que avisa si alguien lo mete delante.
     */
    public function test_the_signature_is_checked_after_the_tenant_bound_receipt(): void
    {
        $this->app->make(HttpKernel::class);

        foreach ([SignedFileUrl::CUSTOMER_ROUTE => ResolvePortalTenant::class, SignedFileUrl::BACKOFFICE_ROUTE => ResolveTenant::class] as $name => $resolver) {
            $route = $this->router()->getRoutes()->getByName($name);
            $this->assertInstanceOf(Route::class, $route, $name);

            $middleware = array_values(array_filter($this->router()->gatherRouteMiddleware($route), 'is_string'));

            $tenant = array_search($resolver, $middleware, true);
            $bindings = array_search(SubstituteBindings::class, $middleware, true);
            $signature = array_search(ValidateSignature::class, $middleware, true);

            $this->assertIsInt($tenant, $name.' no fija su tenant.');
            $this->assertIsInt($bindings, $name);
            $this->assertIsInt($signature, $name.' no exige firma.');
            $this->assertLessThan($bindings, $tenant, $name.' resolvería el comprobante sin tenant.');
            $this->assertLessThan($signature, $bindings, $name.' comprobaría la firma antes que el tenant: lo ajeno daría 403.');
        }
    }

    /**
     * Un comprobante nuestro con su archivo, y la ficha de su titular.
     *
     * @return array{0: Receipt, 1: Customer}
     */
    private function ourReceipt(): array
    {
        Storage::fake(Receipt::DISK);

        $ours = $this->subscribe(Organization::factory()->create());
        $layaway = $this->layawayIn($ours);
        $path = $ours->getKey().'/comprobante.png';
        Storage::disk(Receipt::DISK)->put($path, 'captura');

        $receipt = $this->receiptFor($layaway, ReceiptStatus::PendingReview, ['file_path' => $path, 'file_mime' => 'image/png']);
        $customer = $this->withinOrganization($ours, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));

        return [$receipt, $customer];
    }

    private function router(): Router
    {
        return $this->app->make(Router::class);
    }
}
