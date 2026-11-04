<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Domain\Money\CurrencyDisplay;
use App\Http\Controllers\Marketing\HomeController;
use App\Http\Middleware\AssignVisitorId;
use App\Models\ExchangeRate;
use Carbon\CarbonImmutable;
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

    public function test_the_home_page_shows_the_latest_bcv_rate_of_each_day_newest_first(): void
    {
        ExchangeRate::query()->delete();
        ExchangeRate::factory()->create(['rate' => '880.00000000', 'published_at' => CarbonImmutable::parse('2026-11-02 00:00:00')]);
        ExchangeRate::factory()->create(['rate' => '889.57540000', 'published_at' => CarbonImmutable::parse('2026-11-03 00:00:00')]);
        ExchangeRate::factory()->create(['rate' => '891.10000000', 'published_at' => CarbonImmutable::parse('2026-11-04 00:00:00')]);
        ExchangeRate::factory()->create(['rate' => '891.10000000', 'published_at' => CarbonImmutable::parse('2026-11-04 00:00:00')]);
        ExchangeRate::factory()->parallel()->create(['rate' => '1000.26372600', 'published_at' => CarbonImmutable::parse('2026-11-04 17:00:00')]);

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('rates', [
                ['date' => '2026-11-04', 'rate' => '891.10000000'],
                ['date' => '2026-11-03', 'rate' => '889.57540000'],
                ['date' => '2026-11-02', 'rate' => '880.00000000'],
            ]));
    }

    public function test_the_home_page_shows_at_most_six_days_of_rates(): void
    {
        ExchangeRate::query()->delete();

        foreach (range(1, 9) as $day) {
            ExchangeRate::factory()->create(['published_at' => CarbonImmutable::parse("2026-10-{$day} 00:00:00")]);
        }

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('rates', HomeController::RATE_DAYS)
            ->where('rates.0.date', '2026-10-09'));
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
