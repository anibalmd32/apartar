<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantSupportAccessAction;
use App\Domain\Auditing\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\User;
use App\Notifications\SupportAccessGrantedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\TestCase;

final class GrantSupportAccessActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use RefreshDatabase;

    private const REASON = 'Revisión de balance reportado por el cliente, ticket 42';

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-10-22 10:00:00');
        [$this->organization, $this->owner] = $this->businessWithOwner();
        $this->withinOrganization($this->organization, fn () => Customer::factory()->create(['full_name' => 'María Cliente', 'account_id' => null]));
        $this->withoutOrganization();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_without_a_grant_every_data_section_is_forbidden(): void
    {
        $this->asOperator();

        foreach (['apartados', 'clientes'] as $section) {
            $response = $this->get($this->data($section))->assertForbidden();
            $this->assertStringNotContainsString('María Cliente', (string) $response->getContent());
        }
    }

    public function test_a_grant_opens_reading_and_the_business_sees_it(): void
    {
        Notification::fake();
        $operator = $this->asOperator();

        $this->post($this->base().'/soporte', ['reason' => self::REASON])->assertRedirect();

        $this->get($this->data('clientes'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/support/Customers')
            ->where('customers.0.fullName', 'María Cliente')
            ->missing('customers.0.accountId'));

        $entry = $this->withinOrganization($this->organization, fn (): AuditEntry => AuditEntry::query()->ofEvent(AuditEvent::SupportAccessGranted)->sole());
        $this->assertSame($operator->getKey(), $entry->causer_id);
        Notification::assertSentTo($this->owner, SupportAccessGrantedNotification::class);
        $this->assertSame(1, PlatformActivity::query()->where('action', 'support_access.granted')->where('target_organization_id', $this->organization->getKey())->count());
        $this->assertGreaterThan(0, PlatformActivity::query()->where('action', 'support_access.viewed')->count(), 'la consulta queda registrada.');

        $this->withoutOrganization();
        $this->actingAs($this->owner)->get('/backoffice')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('teamMember.supportAccess.0.operator', $operator->name)
            ->where('teamMember.supportAccess.0.reason', self::REASON));
    }

    public function test_an_expired_grant_stops_working_on_the_next_request(): void
    {
        $operator = $this->asOperator();
        app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);
        $this->get($this->data('apartados'))->assertOk();

        CarbonImmutable::setTestNow('2026-10-22 18:00:01');

        $this->get($this->data('apartados'))->assertForbidden();
    }

    public function test_a_grant_does_not_open_another_business(): void
    {
        $operator = $this->asOperator();
        [$other] = $this->businessWithOwner();
        app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);

        $this->get('/plataforma/organizaciones/'.$other->slug.'/datos/clientes')->assertForbidden();
    }

    public function test_short_reasons_are_refused_and_a_second_grant_extends(): void
    {
        $operator = $this->asOperator();

        $this->post($this->base().'/soporte', ['reason' => 'corto'])->assertSessionHasErrors('reason');

        $first = app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);
        CarbonImmutable::setTestNow('2026-10-22 14:00:00');
        $second = app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame('2026-10-22 22:00:00', $second->refresh()->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_a_grant_never_allows_writing_as_the_business(): void
    {
        $operator = $this->asOperator();
        app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);

        $this->post('/backoffice/productos', ['name' => 'Intruso'])->assertRedirect();
        $this->assertGuest('web');
    }

    private function base(): string
    {
        return '/plataforma/organizaciones/'.$this->organization->slug;
    }

    private function data(string $section): string
    {
        return $this->base().'/datos/'.$section;
    }
}
