<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Surface;
use Illuminate\Http\Request;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SurfaceDomainsTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const BACKOFFICE = 'negocio.apartar.test';

    private const PLATFORM = 'plataforma.apartar.test';

    protected function setUp(): void
    {
        foreach (['BACKOFFICE_DOMAIN' => self::BACKOFFICE, 'PLATFORM_DOMAIN' => self::PLATFORM] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (['BACKOFFICE_DOMAIN', 'PLATFORM_DOMAIN'] as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        parent::tearDown();
    }

    public function test_the_backoffice_lives_at_the_root_of_its_subdomain(): void
    {
        $this->assertSame($this->origin(self::BACKOFFICE).'/login', route('login'));
        $this->assertSame($this->origin(self::BACKOFFICE), route('dashboard'));
        $this->assertSame('', Surface::backofficePath());

        $this->get($this->origin(self::BACKOFFICE).'/login')->assertOk()->assertInertia(fn ($page) => $page->component('backoffice/auth/Login'));
        $this->get($this->origin(self::BACKOFFICE).'/')->assertRedirect($this->origin(self::BACKOFFICE).'/login');
    }

    public function test_the_platform_lives_at_the_root_of_its_subdomain(): void
    {
        $this->assertSame($this->origin(self::PLATFORM).'/login', route('platform.login'));

        $this->get($this->origin(self::PLATFORM).'/login')->assertOk()->assertInertia(fn ($page) => $page->component('platform/auth/Login'));
    }

    public function test_old_links_on_the_main_domain_move_to_the_subdomains_keeping_path_and_query(): void
    {
        $this->get('/backoffice/cobranza?preset=due_today')->assertStatus(301)->assertRedirect($this->origin(self::BACKOFFICE).'/cobranza?preset=due_today');
        $this->get('/backoffice')->assertStatus(301)->assertRedirect($this->origin(self::BACKOFFICE));
        $this->get('/plataforma/login')->assertStatus(301)->assertRedirect($this->origin(self::PLATFORM).'/login');
    }

    public function test_an_inertia_visit_redirected_to_another_subdomain_becomes_a_full_page_load(): void
    {
        $this->get('/backoffice/cobranza', $this->inertiaHeaders())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $this->origin(self::BACKOFFICE).'/cobranza');

        $this->get($this->origin(self::BACKOFFICE).'/', $this->inertiaHeaders())
            ->assertStatus(302)
            ->assertRedirect($this->origin(self::BACKOFFICE).'/login');
    }

    public function test_the_main_domain_keeps_its_home_and_the_page_tells_the_client_where_each_surface_lives(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->component('marketing/Home'));

        $this->get($this->origin(self::BACKOFFICE).'/login')->assertSee('window.apartarSurfaces = {"backoffice":"","platform":"","site":'.json_encode(rtrim((string) config('app.url'), '/')).'}', false);
    }

    public function test_from_a_business_subdomain_the_client_learns_the_main_site_and_not_its_own_host(): void
    {
        $html = $this->get($this->origin(self::BACKOFFICE).'/login')->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('"site":'.json_encode(rtrim((string) config('app.url'), '/')), $html);
        $this->assertStringNotContainsString('"site":'.json_encode($this->origin(self::BACKOFFICE)), $html);
    }

    /**
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ];
    }

    private function origin(string $domain): string
    {
        $port = parse_url((string) config('app.url'), PHP_URL_PORT);

        return 'http://'.$domain.(is_int($port) ? ':'.$port : '');
    }
}
