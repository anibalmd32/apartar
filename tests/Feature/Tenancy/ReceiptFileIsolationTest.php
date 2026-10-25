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
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\CreatesReceipts;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ReceiptFileIsolationTest extends TestCase
{
    use CreatesLayaways, CreatesReceipts, ReusesMigratedDatabase;

    public function test_a_valid_backoffice_url_opened_from_another_business_is_not_found(): void
    {
        [$receipt] = $this->ourReceipt();
        $intruder = $this->withinOrganization($this->neighbourOrganization(), fn (): User => User::factory()->owner()->create());
        $this->withoutOrganization();

        $response = $this->actingAs($intruder)->get(SignedFileUrl::forBackoffice($receipt));

        $response->assertNotFound();
        $this->assertNotSame(403, $response->status());
    }

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

        $this->withoutOrganization();
        $this->actingAs($customer, 'customer')->get(SignedFileUrl::forCustomer($receipt))->assertOk();
    }

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
