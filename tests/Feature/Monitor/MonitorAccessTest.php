<?php

declare(strict_types=1);

namespace Tests\Feature\Monitor;

use App\Livewire\Monitor\PlatformHealth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MonitorAccessTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const TOKEN = 'token-de-prueba-del-monitor-con-32-caracteres-o-mas';

    protected function setUp(): void
    {
        parent::setUp();

        config(['monitor.token' => self::TOKEN]);
        RateLimiter::clear('monitor-access:127.0.0.1');
    }

    public function test_without_a_configured_token_the_monitor_does_not_exist(): void
    {
        config(['monitor.token' => null]);

        $this->get(route('monitor.access'))->assertNotFound();
        $this->get('/pulse')->assertNotFound();
        $this->get('/logs')->assertNotFound();
    }

    public function test_a_short_token_counts_as_not_configured(): void
    {
        config(['monitor.token' => 'corto']);

        $this->get(route('monitor.access'))->assertNotFound();
    }

    public function test_the_panels_send_a_stranger_to_the_access_page(): void
    {
        $this->get('/pulse')->assertRedirect(route('monitor.access'));
        $this->get('/logs')->assertRedirect(route('monitor.access'));
        $this->getJson('/logs/api/folders')->assertForbidden();
    }

    public function test_a_wrong_token_is_refused_and_attempts_are_limited(): void
    {
        $this->get(route('monitor.access'))->assertOk()->assertSee('Token de acceso');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('monitor.access.store'), ['token' => 'otro-token'])->assertSessionHasErrors(['token' => 'El token no es válido.']);
        }

        $this->post(route('monitor.access.store'), ['token' => self::TOKEN])->assertSessionHasErrors('token');
        $this->get('/pulse')->assertRedirect(route('monitor.access'));
    }

    public function test_the_right_token_opens_pulse_and_the_logs_until_logging_out(): void
    {
        $this->post(route('monitor.access.store'), ['token' => self::TOKEN])->assertRedirect('/pulse');

        $this->get('/pulse')->assertOk()->assertSee('monitor.platform-health', false);
        $this->get('/logs')->assertOk();
        $this->get(route('monitor.access'))->assertRedirect('/pulse');

        $this->post(route('monitor.logout'))->assertRedirect(route('monitor.access'));
        $this->get('/pulse')->assertRedirect(route('monitor.access'));
    }

    public function test_rotating_the_token_closes_the_open_sessions(): void
    {
        $this->post(route('monitor.access.store'), ['token' => self::TOKEN]);
        $this->get('/pulse')->assertOk();

        config(['monitor.token' => 'un-token-nuevo-que-tambien-tiene-mas-de-32-caracteres']);

        $this->get('/pulse')->assertRedirect(route('monitor.access'));
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
}
