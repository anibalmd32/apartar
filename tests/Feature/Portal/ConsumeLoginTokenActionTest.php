<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Actions\Customer\ConsumeLoginTokenAction;
use App\Domain\Customer\LoginLinkToken;
use App\Domain\Customer\UnusableLoginLinkException;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\CustomerLoginToken;
use App\Models\Layaway;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ConsumeLoginTokenActionTest extends TestCase
{
    use CreatesLayaways, ReusesMigratedDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-10 10:00:00');
        $this->organization = Organization::factory()->create();
        $this->layaway = $this->layawayIn($this->organization);
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_valid_link_opens_the_session_once(): void
    {
        $plain = $this->linkFor($this->organization, $this->layaway->customer_id);

        $customer = $this->consume($plain);

        $this->assertSame($this->layaway->customer_id, $customer->getKey());
        $this->assertTrue(Auth::guard('customer')->user()?->is($customer));
        $this->assertNotNull(DB::table('customer_login_tokens')->value('consumed_at'));
        $this->assertFalse(app(TenantContext::class)->hasOrganization());

        Auth::guard('customer')->logout();

        $this->expectException(UnusableLoginLinkException::class);
        $this->consume($plain);
    }

    public function test_an_expired_link_is_rejected_and_marked_consumed(): void
    {
        $plain = $this->linkFor($this->organization, $this->layaway->customer_id);
        CarbonImmutable::setTestNow('2026-09-10 10:31:00');

        try {
            $this->consume($plain);
            $this->fail('Un enlace caducado abrió la sesión.');
        } catch (UnusableLoginLinkException $exception) {
            $this->assertSame('token caducado o ya usado', $exception->reason);
        }

        $this->assertSame('2026-09-10 10:31:00', DB::table('customer_login_tokens')->value('consumed_at'));
        $this->assertGuest('customer');
    }

    public function test_unknown_tokens_are_rejected_the_same_way(): void
    {
        $plain = $this->linkFor($this->organization, $this->layaway->customer_id);
        $other = $this->neighbourOrganization();
        $secret = (string) str($plain)->after('.');

        $messages = [];

        foreach (['sin-prefijo', '999999.'.$secret, $other->getKey().'.'.$secret, $this->organization->getKey().'.'.str_repeat('a', 64)] as $token) {
            try {
                $this->consume($token);
                $this->fail("«{$token}» abrió la sesión.");
            } catch (UnusableLoginLinkException $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        $this->assertSame([UnusableLoginLinkException::MESSAGE], array_values(array_unique($messages)));
        $this->assertGuest('customer');
        $this->assertNull(DB::table('customer_login_tokens')->value('consumed_at'), 'Un token ajeno no gasta el de verdad.');
    }

    public function test_it_works_with_the_organization_suspended(): void
    {
        $plain = $this->linkFor($this->organization, $this->layaway->customer_id);
        $this->organization->forceFill(['status' => OrganizationStatus::Suspended])->save();

        $this->consume($plain);

        $this->assertAuthenticated('customer');
    }

    private function consume(string $plain): \App\Models\Customer
    {
        return app(ConsumeLoginTokenAction::class)->handle($plain);
    }

    private function linkFor(Organization $organization, int $customerId): string
    {
        $link = LoginLinkToken::issueFor($organization);

        $this->withinOrganization($organization, function () use ($link, $customerId): void {
            (new CustomerLoginToken)->forceFill([
                'customer_id' => $customerId,
                'token_hash' => $link->tokenHash,
                'expires_at' => $link->expiresAt,
                'requested_ip' => '190.202.10.5',
            ])->save();
        });

        return $link->plainToken;
    }
}
