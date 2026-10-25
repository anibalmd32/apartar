<?php

declare(strict_types=1);

namespace Tests\Feature\Telemetry;

use App\Domain\Telemetry\VisitorIdentity;
use App\Http\Middleware\AssignVisitorId;
use App\Jobs\LinkVisitorEventsToAccount;
use App\Jobs\LinkVisitorEventsToCustomer;
use App\Models\Catalog;
use App\Models\Customer;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class VisitorLinkingTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const VISITOR = '0190f0f0-0000-7000-8000-000000000001';

    /** @var list<Organization> */
    private array $organizations = [];

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-21 12:00:00');
        $this->organizations = [Organization::factory()->create(), Organization::factory()->create(), Organization::factory()->create()];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_first_visit_gets_the_cookie_with_its_exact_attributes(): void
    {
        $cookie = collect($this->get('/')->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === AssignVisitorId::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue(Str::isUuid($cookie->getValue()));
        $this->assertSame('7', $cookie->getValue()[14]);
        $this->assertFalse($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        $this->assertEqualsWithDelta(now()->addYear()->getTimestamp(), $cookie->getExpiresTime(), 86400 * 2);
    }

    public function test_an_existing_cookie_is_kept(): void
    {
        $response = $this->withUnencryptedCookie(AssignVisitorId::COOKIE, self::VISITOR)->get('/');

        $this->assertNull(collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === AssignVisitorId::COOKIE));
    }

    public function test_customer_linking_is_scoped_and_account_linking_is_not(): void
    {
        foreach ($this->organizations as $organization) {
            $this->event((int) $organization->getKey());
        }

        $customer = $this->withinOrganization($this->organizations[1], fn (): Customer => Customer::factory()->create());
        $this->withoutOrganization();

        LinkVisitorEventsToCustomer::dispatchSync((int) $this->organizations[1]->getKey(), (int) $customer->getKey(), self::VISITOR);
        LinkVisitorEventsToAccount::dispatchSync(77, self::VISITOR);

        $rows = DB::table('telemetry_events')->orderBy('id')->get();

        $this->assertSame([null, (int) $customer->getKey(), null], $rows->map(fn (object $row): ?int => $row->customer_id === null ? null : (int) $row->customer_id)->all());
        $this->assertSame([77, 77, 77], $rows->map(fn (object $row): int => (int) $row->account_id)->all());
    }

    public function test_only_the_last_ninety_days_are_linked(): void
    {
        $organization = (int) $this->organizations[0]->getKey();
        $old = $this->event($organization, now()->subDays(91));
        $recent = $this->event($organization, now()->subDays(89));
        $taken = $this->event($organization, now()->subDay(), accountId: 5);

        LinkVisitorEventsToAccount::dispatchSync(77, self::VISITOR);

        $this->assertNull(DB::table('telemetry_events')->where('id', $old)->value('account_id'));
        $this->assertSame(77, (int) DB::table('telemetry_events')->where('id', $recent)->value('account_id'));
        $this->assertSame(5, (int) DB::table('telemetry_events')->where('id', $taken)->value('account_id'));
    }

    public function test_a_missing_customer_links_nothing(): void
    {
        $this->event((int) $this->organizations[0]->getKey());

        LinkVisitorEventsToCustomer::dispatchSync((int) $this->organizations[0]->getKey(), 999_999, self::VISITOR);

        $this->assertSame(0, DB::table('telemetry_events')->whereNotNull('customer_id')->count());
    }

    public function test_a_web_request_remembers_the_identity_of_the_session(): void
    {
        $customer = $this->withinOrganization($this->organizations[0], fn (): Customer => Customer::factory()->create());
        $catalog = $this->withinOrganization($this->organizations[0], fn (): Catalog => Catalog::factory()->published()->create());
        $this->withoutOrganization();

        $this->actingAs($customer, 'customer')
            ->withUnencryptedCookie(AssignVisitorId::COOKIE, self::VISITOR)
            ->get('/c/'.$catalog->slug);

        $this->assertSame((int) $customer->getKey(), VisitorIdentity::of(self::VISITOR)->customerIn((int) $this->organizations[0]->getKey()));
        $this->assertNull(VisitorIdentity::of(self::VISITOR)->customerIn((int) $this->organizations[1]->getKey()));
    }

    private function event(int $organizationId, ?CarbonInterface $at = null, ?int $accountId = null): int
    {
        return DB::table('telemetry_events')->insertGetId([
            'organization_id' => $organizationId,
            'event_name' => 'catalog_viewed',
            'visitor_id' => self::VISITOR,
            'account_id' => $accountId,
            'payload' => '{}',
            'session_id' => 'sesion-abc-123',
            'ip_hash' => str_repeat('a', 64),
            'occurred_at' => $at ?? now()->subHour(),
        ]);
    }
}
