<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Domain\Customer\AccessVerificationFailedException;
use App\Http\Controllers\Portal\DocumentController;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class PortalDocumentCaptureTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->subscribe(Organization::factory()->create(['name' => 'Bodega La Fe']));
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => null, 'document_kind' => null, 'full_name' => 'Rosa Díaz']);
        $this->customer = $this->withinOrganization($this->organization, fn (): Customer => Customer::query()->findOrFail($this->layaway->customer_id));
        $this->withoutOrganization();
    }

    public function test_a_customer_without_document_enters_with_the_code_alone_and_must_write_it_before_anything_else(): void
    {
        $this->enter('', $this->layaway->code)->assertSessionHasNoErrors()->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticatedAs($this->customer, 'customer');

        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.document'));
        $this->get(route('portal.chat.index'))->assertRedirect(route('portal.document'));
        $this->get(route('portal.layaways.show', $this->layaway->code))->assertRedirect(route('portal.document'));

        $this->get(route('portal.document'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('portal/Document')
            ->where('customerName', 'Rosa Díaz')
            ->where('businessName', 'Bodega La Fe')
            ->where('typed', ''));

        $this->post(route('portal.document.store'), ['document' => ''])->assertSessionHasErrors(['document' => 'Escribe tu cédula o RIF para continuar.']);

        $this->post(route('portal.document.store'), ['document' => 'V-20.111.222'])->assertSessionHasNoErrors()->assertRedirect(route('portal.layaways.show', $this->layaway->code));

        $this->assertSame(['V20111222', 'cedula'], $this->documentRow());
        $this->get(route('portal.dashboard'))->assertOk();
        $this->get(route('portal.document'))->assertRedirect(route('portal.dashboard'));
    }

    public function test_the_next_time_the_document_is_required_like_for_everyone(): void
    {
        DB::table('customers')->where('id', $this->customer->getKey())->update(['document_number' => 'V20111222', 'document_kind' => 'cedula']);

        $this->enter('', $this->layaway->code)->assertSessionHasErrors(['document' => AccessVerificationFailedException::DOCUMENT_MISSING_MESSAGE]);
        $this->assertGuest('customer');

        $this->enter('V-20.111.222', $this->layaway->code)->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->customer, 'customer');
    }

    public function test_a_document_typed_at_the_door_comes_prefilled_and_is_not_saved_without_confirmation(): void
    {
        $this->enter('V-20.111.222', $this->layaway->code)->assertSessionHasNoErrors();

        $this->assertSame([null, null], $this->documentRow());
        $this->get(route('portal.document'))->assertInertia(fn (AssertableInertia $page) => $page->where('typed', 'V-20.111.222'));
    }

    public function test_a_document_of_another_record_of_the_business_is_not_taken(): void
    {
        $this->withinOrganization($this->organization, fn () => Customer::factory()->create(['document_number' => 'V20111222']));
        $this->withoutOrganization();
        $this->enter('', $this->layaway->code);

        $this->post(route('portal.document.store'), ['document' => 'V-20.111.222'])
            ->assertSessionHasErrors(['document' => sprintf(DocumentController::TAKEN_MESSAGE, 'Bodega La Fe')]);

        $this->assertSame([null, null], $this->documentRow());
        $this->get(route('portal.dashboard'))->assertRedirect(route('portal.document'));
    }

    public function test_nothing_can_be_sent_from_the_portal_before_writing_the_document(): void
    {
        $this->enter('', $this->layaway->code);

        $this->post(route('portal.receipts.read', $this->layaway->code))->assertStatus(409);
        $this->post(route('portal.chat.store', $this->organization->slug), ['body' => 'Hola'])->assertStatus(409);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function enter(string $document, string $code): TestResponse
    {
        return $this->from('/')->post(route('portal.access.code'), ['document' => $document, 'code' => $code]);
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function documentRow(): array
    {
        $row = DB::table('customers')->where('id', $this->customer->getKey())->first(['document_number', 'document_kind']);
        $this->assertNotNull($row);

        return [$row->document_number, $row->document_kind];
    }
}
