<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Customer\StartScopedSessionAction;
use App\Domain\Customer\AccessVerificationFailedException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Support\DocumentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class StartScopedSessionActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = Organization::factory()->create();
        $this->layaway = $this->layawayIn($organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => 'V12345678']);
        $this->withoutOrganization();
    }

    public function test_it_opens_the_session_of_the_customer_of_that_layaway(): void
    {
        $customer = app(StartScopedSessionAction::class)->handle(DocumentNumber::fromInput('12345678'), ' ap '.substr($this->layaway->code, 3));

        $this->assertSame($this->layaway->customer_id, $customer->getKey());
        $this->assertTrue(Auth::guard('customer')->user()?->is($customer));
        $this->assertFalse(app(TenantContext::class)->hasOrganization(), 'La acción no deja el tenant fijado: lo fija el portal.');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rejections(): array
    {
        return [
            'mal formado' => ['AP-0000', 'código mal formado'],
            'inexistente' => ['AP-ZZZZZZZZ', 'código inexistente'],
        ];
    }

    #[DataProvider('rejections')]
    public function test_each_rejection_carries_its_reason_and_opens_nothing(string $code, string $reason): void
    {
        try {
            app(StartScopedSessionAction::class)->handle(DocumentNumber::fromInput('V12345678'), $code);
            $this->fail('Se abrió una sesión con un código que no abre nada.');
        } catch (AccessVerificationFailedException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame(AccessVerificationFailedException::MESSAGE, $exception->getMessage());
        }

        $this->assertGuest('customer');
    }

    public function test_a_document_that_is_not_the_one_of_the_layaway_opens_nothing(): void
    {
        try {
            app(StartScopedSessionAction::class)->handle(DocumentNumber::fromInput('V87654321'), $this->layaway->code);
            $this->fail('Se abrió la sesión con otra cédula.');
        } catch (AccessVerificationFailedException $exception) {
            $this->assertSame('el documento no es el de la ficha del apartado', $exception->reason);
        }

        $this->assertGuest('customer');
        $this->assertFalse(app(TenantContext::class)->hasOrganization(), 'Un rechazo no deja el tenant del código fijado.');
        $this->assertNull(DB::table('customers')->where('id', $this->layaway->customer_id)->value('last_login_at'));
    }
}
