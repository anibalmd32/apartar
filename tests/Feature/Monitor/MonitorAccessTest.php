<?php

declare(strict_types=1);

namespace Tests\Feature\Monitor;

use App\Livewire\Monitor\PlatformHealth;
use App\Support\MonitorAccess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MonitorAccessTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const TOKEN = 'token-de-prueba-del-monitor-con-32-caracteres-o-mas';

    private const HOST = 'http://monitor.apartar.test';

    private const TELLTALES = ['token', 'monitor', 'apartar', 'pulse', 'telescope', 'csrf', 'acceso', 'contraseña', 'entrar'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.token' => self::TOKEN]);
        RateLimiter::clear('monitor-access:127.0.0.1');
    }

    public function test_without_a_configured_token_the_monitor_does_not_exist(): void
    {
        config(['monitor.token' => null]);

        $this->assertNothing($this->get('/monitor'));
        $this->assertNothing($this->post('/monitor', [MonitorAccess::FIELD => self::TOKEN]));
        $this->assertNothing($this->get('/pulse'));
        $this->assertNothing($this->get('/logs'));
    }

    public function test_a_short_token_counts_as_not_configured(): void
    {
        config(['monitor.token' => 'corto']);

        $this->assertNothing($this->get('/monitor'));
    }

    public function test_the_access_page_is_a_single_unlabelled_field(): void
    {
        $response = $this->get('/monitor')->assertOk();
        $html = (string) $response->getContent();

        $this->assertSame(1, substr_count($html, '<input'));
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<label', $html);
        $this->assertStringNotContainsString('action=', $html);
        $this->assertSame('', trim(strip_tags((string) preg_replace('#<(style|title)[^>]*>.*?</\1>#s', '', $html))));
        $this->assertMatchesRegularExpression('#<title>\s*</title>#', $html);

        foreach (self::TELLTALES as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $html);
        }
    }

    public function test_on_its_own_host_nothing_in_cookies_or_headers_gives_it_away(): void
    {
        config(['monitor.domain' => 'monitor.apartar.test']);

        $page = $this->get(self::HOST.'/monitor')->assertOk();

        $this->assertSame([MonitorAccess::SESSION_COOKIE], array_map(fn ($cookie): string => $cookie->getName(), $page->headers->getCookies()));
        $this->assertNull($page->headers->getCookies()[0]->getDomain());
        $this->assertNoTelltaleHeaders($page);

        $failed = $this->post(self::HOST.'/monitor', [MonitorAccess::FIELD => 'otra-cosa'])->assertRedirect();
        $this->assertNoTelltaleHeaders($failed, except: ['location']);
    }

    public function test_on_its_own_host_the_right_value_opens_the_panels_with_the_neutral_cookie(): void
    {
        config(['monitor.domain' => 'monitor.apartar.test']);

        $this->post(self::HOST.'/monitor', [MonitorAccess::FIELD => self::TOKEN])->assertRedirect('/pulse');

        $this->get(self::HOST.'/pulse')->assertOk()->assertSee('monitor.platform-health', false);
        $this->get(self::HOST.'/logs')->assertOk();
    }

    public function test_on_its_own_host_every_other_page_is_an_empty_not_found(): void
    {
        config(['monitor.domain' => 'monitor.apartar.test']);

        $this->assertNothing($this->get(self::HOST.'/'));
        $this->assertNothing($this->get(self::HOST.'/registro'));
        $this->assertNothing($this->get(self::HOST.'/esto-no-existe'));
        $this->assertNothing($this->get(self::HOST.'/pulse'));
    }

    public function test_the_panels_answer_strangers_with_an_empty_not_found(): void
    {
        $this->assertNothing($this->get('/pulse'));
        $this->assertNothing($this->get('/logs'));
        $this->assertNothing($this->getJson('/logs/api/folders'));
    }

    public function test_a_wrong_value_only_turns_the_field_red_and_attempts_are_limited(): void
    {
        $log = Log::spy();

        $this->get('/monitor')->assertDontSee('class="e"', false);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/monitor', [MonitorAccess::FIELD => 'otra-cosa'])->assertRedirect('/monitor')->assertSessionHasNoErrors();
        }

        $retry = $this->get('/monitor')->assertOk()->assertSee('class="e"', false);
        $this->assertSame('', trim(strip_tags((string) preg_replace('#<(style|title)[^>]*>.*?</\1>#s', '', (string) $retry->getContent()))));

        $this->post('/monitor', [MonitorAccess::FIELD => self::TOKEN])->assertRedirect('/monitor');
        $this->assertNothing($this->get('/pulse'));

        $log->shouldHaveReceived('warning')->with('monitor.access.failed', \Mockery::type('array'))->times(5);
    }

    public function test_the_right_value_opens_pulse_and_the_logs_until_logging_out(): void
    {
        $this->post('/monitor', [MonitorAccess::FIELD => self::TOKEN])->assertRedirect('/pulse');

        $this->get('/pulse')->assertOk()->assertSee('monitor.platform-health', false);
        $this->get('/logs')->assertOk();
        $this->get('/monitor')->assertRedirect('/pulse');

        $this->post('/monitor/salir')->assertRedirect('/monitor');
        $this->assertNothing($this->get('/pulse'));
    }

    public function test_the_old_field_name_no_longer_opens_anything(): void
    {
        $this->post('/monitor', ['token' => self::TOKEN])->assertRedirect('/monitor');

        $this->assertNothing($this->get('/pulse'));
    }

    public function test_rotating_the_token_closes_the_open_sessions(): void
    {
        $this->post('/monitor', [MonitorAccess::FIELD => self::TOKEN]);
        $this->get('/pulse')->assertOk();

        config(['monitor.token' => 'un-token-nuevo-que-tambien-tiene-mas-de-32-caracteres']);

        $this->assertNothing($this->get('/pulse'));
    }

    public function test_the_health_card_shows_the_platform_checks(): void
    {
        Livewire::withoutLazyLoading()->test(PlatformHealth::class)
            ->assertSee('Salud de la plataforma')
            ->assertSee('Reconciliación')
            ->assertSee('Tasa de cambio')
            ->assertSee('Tareas programadas');
    }

    public function test_the_platform_panel_no_longer_has_the_health_page(): void
    {
        $this->assertFalse(app('router')->has('platform.health'));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertNothing(TestResponse $response): void
    {
        $response->assertNotFound();
        $this->assertSame('', (string) $response->getContent());
    }

    /**
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $except
     */
    private function assertNoTelltaleHeaders(TestResponse $response, array $except = []): void
    {
        foreach ($response->headers->allPreserveCase() as $name => $values) {
            if (in_array(strtolower($name), [...$except, 'set-cookie'], true)) {
                continue;
            }

            foreach (self::TELLTALES as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, $name.': '.implode(', ', $values));
            }
        }
    }
}
