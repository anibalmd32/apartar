<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La portada y las dos legales ([05 §7.1], `REQ-IDN-005`).
 */
final class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_opens_without_a_session(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('marketing/Home'));
        $this->assertGuest();
    }

    /**
     * Happy path 2 de `REQ-SUB-001`: «Empezar gratis» lleva al registro. La
     * portada lo enlaza por nombre de ruta, y la ruta existe y responde.
     */
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

    /**
     * Montadas y **marcadas como pendientes de redacción**. `T-045` las va a
     * enlazar junto a una casilla que se acepta y se guarda como evidencia:
     * el día que se redacten, `drafted` cambia en `LegalController` y este
     * caso se pone rojo para que se sustituya por uno sobre el texto.
     */
    #[DataProvider('legalPages')]
    public function test_the_legal_pages_are_mounted_and_marked_as_drafts(string $path, string $key): void
    {
        $this->get($path)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('marketing/Legal')
                ->where('document.key', $key)
                ->where('document.drafted', false)
                ->has('document.mustCover'));
    }
}
