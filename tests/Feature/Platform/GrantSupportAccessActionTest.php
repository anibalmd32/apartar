<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Actions\Platform\GrantSupportAccessAction;
use App\Domain\Auditing\AuditEvent;
use App\Domain\Layaway\LayawayStatus;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\PlatformActivity;
use App\Models\User;
use App\Notifications\SupportAccessGrantedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ActsAsPlatformOperator;
use Tests\Concerns\CreatesLayaways;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class GrantSupportAccessActionTest extends TestCase
{
    use ActsAsPlatformOperator;
    use CreatesLayaways;
    use ReusesMigratedDatabase;

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
            ->where('organization.slug', $this->organization->slug)
            ->missing('customers.0.accountId'));

        $this->get($this->data('apartados'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/support/Layaways')
            ->where('organization.slug', $this->organization->slug));

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

    public function test_abrir_el_acceso_dos_veces_avisa_una_sola_vez_a_cada_dueno(): void
    {
        Notification::fake();
        $operator = $this->asOperator();

        app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);
        app(GrantSupportAccessAction::class)->handle($operator, $this->organization, self::REASON);

        Notification::assertSentToTimes($this->owner, SupportAccessGrantedNotification::class, 1);
        Notification::assertCount(1);
    }

    public function test_los_montos_de_los_apartados_llegan_como_centimos_enteros(): void
    {
        Notification::fake();
        $this->withinOrganization($this->organization, fn () => Layaway::factory()->create());
        $this->withoutOrganization();
        $this->asOperator();

        $this->post($this->base().'/soporte', ['reason' => self::REASON])->assertRedirect();

        $this->get($this->data('apartados'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('platform/support/Layaways')
            ->where('layaways.0.totalCents', 20_000)
            ->where('layaways.0.paidCents', 0)
            ->where('layaways.0.remainingCents', 20_000));
    }

    public function test_un_apartado_cancelado_llega_con_su_estado_y_sin_monto_pendiente(): void
    {
        Notification::fake();
        $this->layawayIn($this->organization, LayawayStatus::Cancelled);
        $this->withoutOrganization();
        $this->asOperator();

        $this->post($this->base().'/soporte', ['reason' => self::REASON])->assertRedirect();

        $this->get($this->data('apartados'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('layaways.0.status', 'cancelled')
            ->where('layaways.0.statusLabel', LayawayStatus::Cancelled->label())
            ->where('layaways.0.remainingCents', null));
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

    public function test_la_auditoria_dice_que_datos_consulto_en_palabras_y_no_con_el_nombre_de_la_ruta(): void
    {
        Notification::fake();
        $this->asOperator();
        $this->post($this->base().'/soporte', ['reason' => self::REASON])->assertRedirect();

        $this->get($this->data('clientes'))->assertOk();
        $this->get($this->data('apartados'))->assertOk();

        $descriptions = PlatformActivity::query()->where('action', 'support_access.viewed')->orderBy('id')->pluck('description')->all();

        $this->assertSame(['Consultó los clientes con acceso de soporte.', 'Consultó los apartados con acceso de soporte.'], $descriptions);
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
