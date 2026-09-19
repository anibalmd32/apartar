<?php

declare(strict_types=1);

namespace Tests\Feature\Exchange;

use App\Actions\Settings\UpdateExchangeRateSettingsAction;
use App\Domain\Exchange\RateSource;
use App\Domain\Settings\SettingKey;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * La acción que cambia la fuente de tasa de un negocio (`REQ-FX-003` RB-1).
 *
 * Lo que la prueba de la ruta no ve: **qué claves viaja** en cada caso, que es
 * lo único que esta acción decide por su cuenta. Todo lo demás —la
 * transacción, la auditoría, la invalidación de caché— lo hace
 * `UpdateSettingsAction`, a propósito: dos caminos de escritura sobre las
 * mismas dos claves divergirían, y el día que lo hicieran el síntoma sería una
 * tasa guardada sin entrada de auditoría.
 */
final class UpdateExchangeRateSettingsActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();

        $this->withinOrganization($this->organization, function (): void {
            foreach (SettingKey::cases() as $key) {
                OrganizationSetting::query()->create([
                    'key' => $key,
                    'value' => $key->defaultValue(),
                    'updated_by' => null,
                ]);
            }
        });
    }

    public function test_it_saves_the_chosen_source(): void
    {
        $this->changeSource(RateSource::Parallel, null);

        $this->assertSame('parallel', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    /**
     * SP-1 · elegir manual **exige** la tasa en la misma operación, y el
     * rechazo llega antes de escribir nada: el estado intermedio «fuente
     * manual, tasa nula» no existe ni dentro de una transacción.
     */
    public function test_it_refuses_manual_without_a_rate(): void
    {
        try {
            $this->changeSource(RateSource::Manual, null);
            $this->fail('Se esperaba que exigiera la tasa manual.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(SettingKey::ExchangeRateManual->value, $exception->errors());
        }

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
    }

    public function test_it_saves_the_pair_together(): void
    {
        $this->changeSource(RateSource::Manual, '55.00');

        $this->assertSame('manual', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    /**
     * **SP-6 · sin tasa en el payload, la guardada no se toca.** La clave se
     * omite en lugar de enviarse a `null`: mandar `null` la borraría, y volver
     * a «manual» dentro de un mes obligaría a escribirla otra vez sin que
     * nadie hubiera pedido borrarla.
     */
    public function test_it_keeps_the_stored_manual_rate_when_none_is_submitted(): void
    {
        $this->changeSource(RateSource::Manual, '55.00');
        $this->changeSource(RateSource::Bcv, null);

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('55.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    /**
     * Y si la escriben mientras están en otra fuente, se guarda: quien prepara
     * su tasa antes de cambiarse no tiene por qué hacerlo en dos pasos.
     */
    public function test_it_stores_a_manual_rate_submitted_while_on_another_source(): void
    {
        $this->changeSource(RateSource::Bcv, '48.00');

        $this->assertSame('bcv', $this->settingOf(SettingKey::ExchangeRateSource));
        $this->assertSame('48.00', $this->settingOf(SettingKey::ExchangeRateManual));
    }

    /**
     * SP-2 · la tasa manual se valida contra el rango de `SettingKey`, que es
     * donde vive: aquí no hay una segunda copia del `gt:0`.
     */
    public function test_it_refuses_a_rate_that_is_not_positive(): void
    {
        $this->expectException(ValidationException::class);

        $this->changeSource(RateSource::Manual, '0');
    }

    /**
     * SP-3 · la capacidad es `Settings`, reservada al `owner` por RB-1 de
     * `REQ-ORG-003`. La policy es la misma de los demás ajustes porque la tasa
     * es uno de ellos.
     */
    public function test_a_member_cannot_run_it(): void
    {
        $member = $this->withinOrganization($this->organization, fn (): User => User::factory()->create());

        $this->expectException(AuthorizationException::class);

        $this->withinOrganization(
            $this->organization,
            fn (): array => app(UpdateExchangeRateSettingsAction::class)->handle(RateSource::Parallel, null, $member),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function changeSource(RateSource $source, ?string $manualRate): array
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        return $this->withinOrganization(
            $this->organization,
            fn (): array => app(UpdateExchangeRateSettingsAction::class)->handle($source, $manualRate, $owner),
        );
    }

    private function settingOf(SettingKey $key): mixed
    {
        app(Settings::class)->forget($this->organization);

        return $this->withinOrganization($this->organization, fn (): mixed => app(Settings::class)->get($key));
    }
}
