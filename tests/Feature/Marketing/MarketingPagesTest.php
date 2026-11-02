<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Money\CurrencyDisplay;
use App\Http\Middleware\AssignVisitorId;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MarketingPagesTest extends TestCase
{
    use ReusesMigratedDatabase;

    public function test_the_home_page_opens_without_a_session(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/Home'));
        $this->assertGuest();
    }

    public function test_start_for_free_leads_to_the_registration(): void
    {
        $this->assertSame(url('/registro'), route('register'));
        $this->get(route('register'))->assertOk();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legalPages(): iterable
    {
        yield 'términos' => ['/terminos', 'terms'];
        yield 'privacidad' => ['/privacidad', 'privacy'];
    }

    #[DataProvider('legalPages')]
    public function test_the_legal_pages_render_their_text_with_the_operator_and_the_date(string $path, string $key): void
    {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('marketing/Legal')
                ->where('document.key', $key)
                ->where('document.updatedOn', '28 de octubre de 2026')
                ->where('document.html', fn (string $html): bool => preg_match('/<h2 id="[a-z-]+">1\. /', $html) === 1
                    && str_contains($html, 'V-26.777.308')
                    && str_contains($html, 'mailto:anibalmd32@gmail.com')
                    && str_contains($html, 'República Bolivariana de Venezuela')));
    }

    public function test_the_terms_say_the_payment_commitment_is_with_the_business(): void
    {
        $this->get('/terminos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.html', fn (string $html): bool => str_contains($html, 'Tu compromiso de pago es con el negocio, no con Apartar')
                && str_contains($html, 'Ley sobre Mensajes de Datos y Firmas Electrónicas')));
    }

    public function test_the_privacy_policy_lists_the_cookies_the_app_sets(): void
    {
        $this->get('/privacidad')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.html', fn (string $html): bool => str_contains($html, '<table>')
                && str_contains($html, config('session.cookie'))
                && str_contains($html, AssignVisitorId::COOKIE)
                && str_contains($html, CurrencyDisplay::COOKIE)));
    }

    public function test_the_terms_list_their_sections_with_an_anchor_each(): void
    {
        $this->get('/terminos')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('document.sections', 14)
            ->where('document.sections.0', ['id' => 'quien-presta-el-servicio', 'title' => '1. Quién presta el servicio'])
            ->where('document.sections.13', ['id' => 'ley-aplicable', 'title' => '14. Ley aplicable'])
            ->where('document.html', fn (string $html): bool => str_contains($html, '<h2 id="quien-presta-el-servicio">1. Quién presta el servicio</h2>')
                && str_contains($html, '<h2 id="ley-aplicable">')));
    }

    public function test_the_privacy_policy_lists_its_sections_too(): void
    {
        $this->get('/privacidad')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('document.sections', 12)
            ->where('document.sections.0', ['id' => 'responsable', 'title' => '1. Responsable']));
    }

    #[DataProvider('legalPages')]
    public function test_the_operator_document_does_not_break_at_the_hyphen(string $path, string $key): void
    {
        $this->get($path)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.key', $key)
            ->where('document.html', fn (string $html): bool => str_contains($html, '<span class="whitespace-nowrap">V-26.777.308</span>')));
    }

    public function test_the_terms_link_with_descriptive_text_instead_of_raw_paths(): void
    {
        $this->get('/terminos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.html', fn (string $html): bool => str_contains($html, '<a href="/mi-cuenta">Mi cuenta</a>')
                && str_contains($html, '<a href="/precios">página de planes y precios</a>')
                && ! str_contains($html, '>/mi-cuenta<')
                && ! str_contains($html, '>/precios<')));
    }
}
