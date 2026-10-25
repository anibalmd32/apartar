<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Actions\Organization\RegisterOrganizationAction;
use App\Actions\Organization\RegisterOrganizationData;
use App\Actions\Subscription\StartFreeSubscriptionAction;
use App\Domain\Settings\SettingKey;
use App\Domain\Subscription\MissingFreePlanException;
use App\Domain\Subscription\SubscriptionStatus;
use App\Domain\Subscription\UsageMetric;
use App\Domain\Tenancy\OrganizationRole;
use App\Domain\Tenancy\OrganizationStatus;
use App\Domain\Tenancy\SlugTakenException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationSetting;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class RegisterOrganizationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    public function test_it_creates_the_organization_its_owner_its_subscription_and_its_settings(): void
    {
        $organization = $this->register();

        $this->assertSame('Ferretería El Tornillo', $organization->name);
        $this->assertSame('ferreteria-el-tornillo', $organization->slug);
        $this->assertSame(OrganizationStatus::Active, $organization->status);
        $this->assertNull($organization->document, 'El RIF es opcional en el registro.');

        $this->withinOrganization($organization, function (): void {
            $owner = User::query()->sole();

            $this->assertSame(OrganizationRole::Owner, $owner->role);
            $this->assertSame('ana@ejemplo.test', $owner->email);
            $this->assertNull($owner->email_verified_at, 'El correo nace sin verificar.');

            $subscription = Subscription::query()->sole();

            $this->assertSame(SubscriptionStatus::Active, $subscription->status);
            $this->assertNull($subscription->current_period_end);

            $this->assertSame(10, OrganizationSetting::query()->count());
        });
    }

    public function test_the_initial_plan_is_free(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $this->assertSame(
                StartFreeSubscriptionAction::FREE_PLAN_CODE,
                Subscription::query()->sole()->plan->code,
            );
        });
    }

    public function test_it_seeds_the_ten_default_settings(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $settings = OrganizationSetting::query()->get()->keyBy(
                fn (OrganizationSetting $setting): string => $setting->key->value,
            );

            $this->assertCount(10, $settings);

            $this->assertArrayNotHasKey('storefront_enabled', $settings->all());

            foreach (SettingKey::cases() as $key) {
                $this->assertSame($key->defaultValue(), $settings[$key->value]?->value, sprintf(
                    'El ajuste %s no nació con su valor por defecto.',
                    $key->value,
                ));
            }

            $this->assertNull($settings['grace_period_days']?->updated_by);
        });
    }

    public function test_it_starts_the_usage_counters_with_what_it_has(): void
    {
        $organization = $this->register();

        $this->withinOrganization($organization, function (): void {
            $this->assertSame(6, UsageCounter::query()->count());
            $this->assertSame(1, (int) UsageCounter::query()->where('metric', UsageMetric::Users)->value('value'));
            $this->assertSame(1, (int) UsageCounter::query()->sum('value'));
        });
    }

    public function test_it_signs_the_owner_in(): void
    {
        $organization = $this->register();

        $this->assertTrue(Auth::guard('web')->check());

        $owner = $this->withinOrganization($organization, fn (): User => User::query()->sole());

        $this->assertSame($owner->getKey(), Auth::guard('web')->id());
    }

    public function test_a_failure_in_the_fourth_creation_leaves_nothing_behind(): void
    {
        OrganizationSetting::creating(function (): void {
            throw new RuntimeException('fallo al sembrar los ajustes');
        });

        try {
            $this->register();

            $this->fail('Se esperaba que el registro fallara.');
        } catch (RuntimeException $exception) {
            $this->assertSame('fallo al sembrar los ajustes', $exception->getMessage());
        }

        $this->assertNothingWasCreated();
        $this->assertFalse(Auth::guard('web')->check(), 'Quedó una sesión de un registro deshecho.');
    }

    public function test_without_the_free_plan_the_registration_rolls_back(): void
    {
        Plan::query()->where('code', StartFreeSubscriptionAction::FREE_PLAN_CODE)->delete();

        $this->expectException(MissingFreePlanException::class);

        try {
            $this->register();
        } finally {
            $this->assertNothingWasCreated();
        }
    }

    public function test_a_taken_slug_comes_back_with_a_free_variant(): void
    {
        $this->register();

        try {
            $this->register(email: 'otra@ejemplo.test');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo', $exception->slug);
            $this->assertSame('ferreteria-el-tornillo-2', $exception->suggestion);
        }

        $this->assertSame(1, Organization::query()->count());
    }

    public function test_the_suggestion_skips_the_variants_already_taken(): void
    {
        $this->register();
        Organization::factory()->create(['slug' => 'ferreteria-el-tornillo-2']);

        try {
            $this->register(email: 'otra@ejemplo.test');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo-3', $exception->suggestion);
        }
    }

    public function test_a_name_that_slugifies_to_a_reserved_word_is_refused_with_a_variant(): void
    {
        try {
            $this->register(name: 'Precios');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertStringContainsString('reservado', $exception->getMessage());
            $this->assertSame('precios-2', $exception->suggestion);
        }

        $this->assertNothingWasCreated();
    }

    public function test_a_name_without_a_slug_refuses_to_invent_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        try {
            $this->register(name: '🎉🎉🎉');
        } finally {
            $this->assertNothingWasCreated();
        }
    }

    public function test_a_slug_taken_mid_flight_still_comes_back_with_a_variant(): void
    {
        $rivalCreated = false;

        Organization::creating(function () use (&$rivalCreated): void {
            if ($rivalCreated) {
                return;
            }

            $rivalCreated = true;

            DB::table('organizations')->insert([
                'name' => 'La que se adelantó',
                'slug' => 'ferreteria-el-tornillo',
                'timezone' => 'America/Caracas',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        try {
            $this->register();

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertSame('ferreteria-el-tornillo-2', $exception->suggestion);
        }

        $this->assertNothingWasCreated();
    }

    public function test_it_falls_back_to_a_random_suffix_when_every_variant_is_taken(): void
    {
        Organization::factory()->create(['slug' => 'tienda']);

        for ($suffix = 2; $suffix <= 50; $suffix++) {
            Organization::factory()->create(['slug' => 'tienda-'.$suffix]);
        }

        try {
            $this->register(name: 'Tienda');

            $this->fail('Se esperaba SlugTakenException.');
        } catch (SlugTakenException $exception) {
            $this->assertMatchesRegularExpression('/^tienda-\d{4}$/', $exception->suggestion);
        }
    }

    public function test_a_hand_written_slug_wins_over_the_name(): void
    {
        $organization = $this->register(slug: 'el-tornillo');

        $this->assertSame('el-tornillo', $organization->slug);
    }

    public function test_it_leaves_the_tenant_context_as_it_found_it(): void
    {
        $this->withoutOrganization();

        $this->register();

        $this->assertFalse(app(TenantContext::class)->hasOrganization());
    }

    private function register(
        string $name = 'Ferretería El Tornillo',
        string $slug = '',
        string $email = 'ana@ejemplo.test',
    ): Organization {
        return app(RegisterOrganizationAction::class)->handle(new RegisterOrganizationData(
            organizationName: $name,
            organizationSlug: $slug,
            ownerName: 'Ana Pérez',
            ownerEmail: $email,
            ownerPassword: 'ContraseñaLarga123',
        ));
    }

    private function assertNothingWasCreated(): void
    {
        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('subscriptions')->count());
        $this->assertSame(0, DB::table('organization_settings')->count());
        $this->assertSame(0, DB::table('usage_counters')->count());
    }
}
