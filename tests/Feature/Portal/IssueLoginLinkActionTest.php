<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Customer\IssueLoginLinkAction;
use App\Domain\Customer\LoginLinkToken;
use App\Domain\Tenancy\TenantContext;
use App\Models\Customer;
use App\Models\Organization;
use App\Notifications\CustomerLoginLinkNotification;
use App\Support\DocumentNumber;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

final class IssueLoginLinkActionTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_document_in_three_organizations_gets_three_scoped_links(): void
    {
        $organizations = [
            Organization::factory()->create(['name' => 'Ferretería Oriente']),
            Organization::factory()->create(['name' => 'Mueblería Centro']),
            $this->neighbourOrganization(['name' => 'Electro Sur']),
        ];

        foreach ($organizations as $organization) {
            $this->customerIn($organization, 'V12345678', 'maria@ejemplo.test');
        }

        $sent = $this->issue('V-12.345.678', 'Maria@Ejemplo.test');

        $this->assertSame(3, $sent);
        Notification::assertSentTimes(CustomerLoginLinkNotification::class, 3);

        $businesses = [];
        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, function (CustomerLoginLinkNotification $notification) use (&$businesses): bool {
            $businesses[] = $notification->businessName;

            return true;
        });
        sort($businesses);
        $this->assertSame(['Electro Sur', 'Ferretería Oriente', 'Mueblería Centro'], $businesses);

        foreach ($organizations as $organization) {
            $this->assertSame(1, DB::table('customer_login_tokens')->where('organization_id', $organization->getKey())->count());
        }

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    public function test_nothing_is_sent_when_the_data_does_not_match(): void
    {
        $organization = Organization::factory()->create();
        $this->customerIn($organization, 'V12345678', 'maria@ejemplo.test');
        $this->customerIn($organization, 'V87654321', null);

        $this->assertSame(0, $this->issue('V-12.345.678', 'otra@ejemplo.test'));
        $this->assertSame(0, $this->issue('V-11.111.111', 'maria@ejemplo.test'));
        $this->assertSame(0, $this->issue('V-87.654.321', ''));

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('customer_login_tokens')->count());
    }

    public function test_it_stores_only_the_hash_and_a_new_link_expires_the_previous_one(): void
    {
        $organization = Organization::factory()->create();
        $this->customerIn($organization, 'V12345678', 'maria@ejemplo.test');

        $this->issue('V-12.345.678', 'maria@ejemplo.test');

        $first = null;
        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, function (CustomerLoginLinkNotification $notification) use (&$first): bool {
            $first = $notification;

            return true;
        });
        $this->assertInstanceOf(CustomerLoginLinkNotification::class, $first);
        $plain = (string) str($first->url)->afterLast('/');

        $row = DB::table('customer_login_tokens')->first();
        $this->assertNotNull($row);
        $this->assertSame(LoginLinkToken::hash($plain), $row->token_hash);
        $this->assertStringNotContainsString($plain, json_encode($row, JSON_THROW_ON_ERROR));
        $this->assertSame('2026-09-10 10:30:00', $row->expires_at);
        $this->assertSame((int) $organization->getKey(), LoginLinkToken::organizationIdIn($plain));

        CarbonImmutable::setTestNow('2026-09-10 10:05:00');
        $this->issue('V-12.345.678', 'maria@ejemplo.test');

        $this->assertSame('2026-09-10 10:05:00', DB::table('customer_login_tokens')->where('id', $row->id)->value('expires_at'));
        $this->assertSame(1, DB::table('customer_login_tokens')->where('expires_at', '>', '2026-09-10 10:05:00')->count());
    }

    private function issue(string $document, string $email): int
    {
        return app(IssueLoginLinkAction::class)->handle(DocumentNumber::fromInput($document), $email, '190.202.10.5');
    }

    private function customerIn(Organization $organization, string $document, ?string $email): Customer
    {
        $layaway = $this->layawayIn($organization);
        DB::table('customers')->where('id', $layaway->customer_id)->update(['document_number' => $document, 'email' => $email]);

        return $this->withinOrganization($organization, fn (): Customer => Customer::query()->findOrFail($layaway->customer_id));
    }
}
