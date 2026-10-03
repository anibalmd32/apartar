<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Http\Requests\Portal\RequestLoginLinkRequest;
use App\Models\Layaway;
use App\Models\Organization;
use App\Notifications\CustomerLoginLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\CreatesLayaways;
use Tests\TestCase;

/**
 * El enlace de acceso por correo, de punta a punta por HTTP (`REQ-IDN-003`).
 */
final class LoginLinkTest extends TestCase
{
    use CreatesLayaways, RefreshDatabase;

    private Organization $organization;

    private Layaway $layaway;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->organization = Organization::factory()->create(['name' => 'Ferretería Oriente']);
        $this->layaway = $this->layawayIn($this->organization);
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['document_number' => 'V12345678', 'email' => 'maria@ejemplo.test']);
        $this->withoutOrganization();
    }

    /**
     * El camino feliz: pedir, recibir, abrir (sin entrar), confirmar y entrar.
     */
    public function test_request_open_confirm_and_enter(): void
    {
        $this->requestLink('V-12.345.678', 'maria@ejemplo.test')
            ->assertRedirect(route('portal.access'))
            ->assertSessionHas('status', RequestLoginLinkRequest::SENT_MESSAGE);

        $url = $this->sentUrl();
        $path = (string) parse_url($url, PHP_URL_PATH);

        // RB-9 y SP-7 · abrirlo no gasta nada.
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/LoginLink'));
        $this->assertNull(DB::table('customer_login_tokens')->value('consumed_at'));
        $this->assertGuest('customer');

        $this->post($path)->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated('customer');

        // Un solo uso: el segundo canje, ya sin sesión, no entra.
        $this->post(route('portal.logout'));
        $this->post($path)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/LoginLinkUnusable'));
        $this->assertGuest('customer');
    }

    /**
     * RB-2 y SP-3: datos que no coinciden responden **exactamente** igual, y
     * no envían nada.
     */
    public function test_mismatched_data_answers_the_same_and_sends_nothing(): void
    {
        $this->requestLink('V-12.345.678', 'otra@ejemplo.test')
            ->assertRedirect(route('portal.access'))
            ->assertSessionHas('status', RequestLoginLinkRequest::SENT_MESSAGE);

        Notification::assertNothingSent();
    }

    /**
     * RB-6 y SP-6: tres solicitudes cada 15 minutos; la cuarta es `429`, y
     * cuenta toda solicitud, coincida o no.
     */
    public function test_the_fourth_request_is_rate_limited(): void
    {
        foreach (range(1, RequestLoginLinkRequest::MAX_ATTEMPTS) as $attempt) {
            $this->requestLink('V-12.345.678', 'nadie@ejemplo.test')->assertRedirect(route('portal.access'));
        }

        $response = $this->requestLink('V-12.345.678', 'maria@ejemplo.test');

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        Notification::assertNothingSent();
    }

    /**
     * RB-8: el correo lleva el negocio, el enlace y la caducidad, y nada de
     * dinero ni de productos.
     */
    public function test_the_email_carries_no_amounts_nor_products(): void
    {
        $this->requestLink('V-12.345.678', 'maria@ejemplo.test');

        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, function (CustomerLoginLinkNotification $notification): bool {
            $mail = $notification->toMail(new AnonymousNotifiable);
            $text = implode("\n", [$mail->subject, ...$mail->introLines, ...$mail->outroLines]);

            $this->assertStringContainsString('Ferretería Oriente', $text);
            $this->assertStringContainsString('Caduca', $text);
            $this->assertStringNotContainsString('$', $text);
            $this->assertStringNotContainsString($this->layaway->product_name_snapshot, $text);
            $this->assertStringNotContainsString($this->layaway->code, $text);

            return true;
        });
    }

    /**
     * SP-4: una ficha sin correo no tiene esta vía; la respuesta es la misma y
     * no sale nada.
     */
    public function test_a_customer_without_email_gets_nothing(): void
    {
        DB::table('customers')->where('id', $this->layaway->customer_id)->update(['email' => null]);

        $this->requestLink('V-12.345.678', 'maria@ejemplo.test')->assertSessionHas('status', RequestLoginLinkRequest::SENT_MESSAGE);

        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('customer_login_tokens')->count());
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function requestLink(string $document, string $email): \Illuminate\Testing\TestResponse
    {
        return $this->from(route('portal.access'))->post(route('portal.link.store'), ['document' => $document, 'email' => $email]);
    }

    private function sentUrl(): string
    {
        $url = '';

        Notification::assertSentOnDemand(CustomerLoginLinkNotification::class, function (CustomerLoginLinkNotification $notification) use (&$url): bool {
            $url = $notification->url;

            return true;
        });

        return $url;
    }
}
