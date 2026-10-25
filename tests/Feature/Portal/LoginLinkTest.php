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

    public function test_request_open_confirm_and_enter(): void
    {
        $this->requestLink('V-12.345.678', 'maria@ejemplo.test')
            ->assertRedirect(route('portal.access'))
            ->assertSessionHas('status', RequestLoginLinkRequest::SENT_MESSAGE);

        $url = $this->sentUrl();
        $path = (string) parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/LoginLink'));
        $this->assertNull(DB::table('customer_login_tokens')->value('consumed_at'));
        $this->assertGuest('customer');

        $this->post($path)->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated('customer');

        $this->post(route('portal.logout'));
        $this->post($path)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('portal/LoginLinkUnusable'));
        $this->assertGuest('customer');
    }

    public function test_the_confirmation_tells_who_enters_and_remember_is_a_choice(): void
    {
        $this->requestLink('V-12.345.678', 'maria@ejemplo.test');
        $path = (string) parse_url($this->sentUrl(), PHP_URL_PATH);

        $this->get($path)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preview.businessName', 'Ferretería Oriente')
            ->where('preview.document', 'V-12.***.678'));
        $this->assertNull(DB::table('customer_login_tokens')->value('consumed_at'));

        $response = $this->post($path, ['remember' => '0'])->assertRedirect(route('portal.dashboard'));
        $this->assertAuthenticated('customer');
        $this->assertEmpty(array_filter(
            $response->headers->getCookies(),
            fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_customer_'),
        ));
    }

    public function test_an_unusable_link_previews_nothing(): void
    {
        $this->get(route('portal.link.show', ['token' => '999.no-existe']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('preview', null));
    }

    public function test_mismatched_data_answers_the_same_and_sends_nothing(): void
    {
        $this->requestLink('V-12.345.678', 'otra@ejemplo.test')
            ->assertRedirect(route('portal.access'))
            ->assertSessionHas('status', RequestLoginLinkRequest::SENT_MESSAGE);

        Notification::assertNothingSent();
    }

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
