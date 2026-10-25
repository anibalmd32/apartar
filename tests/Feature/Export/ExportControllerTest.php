<?php

declare(strict_types=1);

namespace Tests\Feature\Export;

use App\Domain\Export\ExportStatus;
use App\Domain\Export\ExportType;
use App\Domain\Subscription\PlanFeature;
use App\Http\Middleware\HandleInertiaRequests;
use App\Jobs\GenerateExport;
use App\Models\ExportJob;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ExportControllerTest extends TestCase
{
    use ReusesMigratedDatabase;

    private Organization $organization;

    private User $owner;

    private Plan $free;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(ExportJob::DISK);
        $this->free = Plan::factory()->create(['code' => 'free', 'name' => 'Free', 'position' => 1, 'features' => PlanFeature::noneEnabled()]);
        Plan::factory()->withEveryFeature()->create(['code' => 'pro', 'name' => 'Pro', 'position' => 2]);
        $this->organization = Organization::factory()->create();
        $this->owner = $this->withinOrganization($this->organization, function (): User {
            Subscription::factory()->forPlan(Plan::query()->where('code', 'pro')->firstOrFail())->create();

            return User::factory()->owner()->create();
        });
        $this->withoutOrganization();
    }

    public function test_requesting_queues_and_goes_to_the_list(): void
    {
        $this->actingAs($this->owner)
            ->post('/backoffice/exportaciones', ['type' => 'collections', 'filters' => ['preset' => 'due_today']])
            ->assertRedirect('/backoffice/exportaciones')
            ->assertSessionHas('status');

        Queue::assertPushedOn('exports', GenerateExport::class);

        $this->get('/backoffice/exportaciones')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('backoffice/exports/Index')
            ->where('canExport', true)
            ->where('exports.0.status', 'queued')
            ->where('exports.0.downloadUrl', null)
            ->where('exports.0.waitingMinutes', 0));
    }

    public function test_the_list_offers_the_types_and_the_filters_to_retry_a_failure(): void
    {
        $this->withinOrganization($this->organization, fn () => ExportJob::factory()->create([
            'type' => 'collections',
            'status' => ExportStatus::Failed,
            'filters' => ['preset' => 'due_today'],
            'error_message' => 'Reintenta en unos minutos.',
        ]));
        $this->withoutOrganization();

        $this->actingAs($this->owner)->get('/backoffice/exportaciones')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('types.0.allowed', true)
            ->where('exports.0.status', 'failed')
            ->where('exports.0.retryFilters', ['preset' => 'due_today']));
    }

    public function test_free_gets_403_with_the_reason(): void
    {
        $this->onFree();

        $this->actingAs($this->owner)
            ->postJson('/backoffice/exportaciones', ['type' => 'customers'])
            ->assertForbidden()
            ->assertJsonPath('feature.feature', 'excel_export')
            ->assertJsonPath('feature.minimumPlanName', 'Pro');

        $this->from('/backoffice/clientes')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())])
            ->post('/backoffice/exportaciones', ['type' => 'customers'])
            ->assertRedirect('/backoffice/clientes')
            ->assertSessionHas('planFeature', fn (array $body): bool => $body['feature']['feature'] === 'excel_export');

        Queue::assertNothingPushed();
    }

    public function test_the_signed_link_downloads_expires_with_403_and_a_purged_file_is_410(): void
    {
        $export = $this->completed();

        $this->actingAs($this->owner)->get($export->downloadUrl())->assertOk()->assertDownload($export->downloadName());

        $expired = URL::temporarySignedRoute('exports.download', CarbonImmutable::now()->subMinute(), ['export' => $export->getKey()]);
        $this->get($expired)->assertForbidden();
        $this->get('/backoffice/exportaciones/'.$export->getKey().'/descargar')->assertForbidden();

        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($export->getKey())->update(['status' => ExportStatus::Expired]));
        $this->withoutOrganization();
        $this->get($export->downloadUrl())->assertStatus(410)->assertSee('siete días');
    }

    public function test_another_business_gets_403_with_a_valid_signed_link(): void
    {
        $export = $this->completed();
        $url = $export->downloadUrl();
        $stranger = $this->withinOrganization($this->neighbourOrganization(), function (): User {
            $this->subscribeWithoutLimits();

            return User::factory()->owner()->create();
        });
        $this->withoutOrganization();

        $this->actingAs($stranger)->get($url)->assertForbidden();
        $this->get('/backoffice/exportaciones')->assertInertia(fn (AssertableInertia $page) => $page->has('exports', 0));
    }

    public function test_going_down_to_free_keeps_the_existing_downloads(): void
    {
        $export = $this->completed();
        $this->onFree();

        $this->actingAs($this->owner)->get($export->downloadUrl())->assertOk();
        $this->get('/backoffice/exportaciones')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canExport', false)
            ->where('exports.0.status', 'completed')
            ->where('exports.0.downloadUrl', fn (?string $url): bool => $url !== null));
    }

    public function test_the_route_declares_the_paid_feature_and_a_throttle(): void
    {
        $middleware = app('router')->getRoutes()->getByName('exports.store')?->gatherMiddleware() ?? [];

        $this->assertContains('plan-feature:excel_export', $middleware);
        $this->assertNotEmpty(array_filter($middleware, fn (string $name): bool => str_starts_with($name, 'throttle:')));
    }

    public function test_a_reused_export_says_so(): void
    {
        $this->actingAs($this->owner)->post('/backoffice/exportaciones', ['type' => 'collections', 'filters' => ['preset' => 'due_today']]);

        $this->post('/backoffice/exportaciones', ['type' => 'collections', 'filters' => ['preset' => 'overdue_over_15_days']])
            ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Ya se está generando una exportación de cobranza'));
    }

    public function test_a_due_or_missing_file_is_410_and_the_expired_link_shows_the_way_out(): void
    {
        $due = $this->completed();
        $this->withinOrganization($this->organization, fn () => ExportJob::query()->whereKey($due->getKey())->update(['expires_at' => CarbonImmutable::now()->subMinute()]));
        $this->withoutOrganization();
        $this->actingAs($this->owner)->get($due->downloadUrl())->assertStatus(410);

        $missing = $this->completed();
        Storage::disk(ExportJob::DISK)->delete((string) $missing->file_path);
        $this->get($missing->downloadUrl())->assertStatus(410);

        $queued = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::factory()->create());
        $this->withoutOrganization();
        $this->get($queued->downloadUrl())->assertNotFound();

        $expired = URL::temporarySignedRoute('exports.download', CarbonImmutable::now()->subMinute(), ['export' => $missing->getKey()]);
        $this->get($expired)->assertForbidden()->assertSee(route('exports.index'), false)->assertSee('caducó');
    }

    private function completed(): ExportJob
    {
        $export = $this->withinOrganization($this->organization, fn (): ExportJob => ExportJob::factory()->completed()->create(['type' => ExportType::Collections, 'requested_by' => $this->owner->getKey()]));
        Storage::disk(ExportJob::DISK)->put((string) $export->file_path, 'xlsx');
        $this->withoutOrganization();

        return $export;
    }

    private function onFree(): void
    {
        $this->withinOrganization($this->organization, fn () => Subscription::query()->update(['plan_id' => $this->free->getKey()]));
        $this->withoutOrganization();
    }
}
