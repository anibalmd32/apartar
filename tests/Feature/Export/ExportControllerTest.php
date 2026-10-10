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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Las rutas de las exportaciones (`REQ-BOF-043` RB-1, RB-5, RB-12, SP-1,
 * SP-3, SP-4, SP-9, SP-12, `T-070`).
 */
final class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

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

    /**
     * RB-2 · pedir encola y vuelve a la lista con el aviso; la fila sale en
     * `queued` y el job en su cola, sin archivo.
     */
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

    /**
     * SP-1 · en Free, la llamada directa por API da `403` con el motivo y el
     * plan que la incluye; una visita de Inertia vuelve con `planFeature`.
     * Nada se encola.
     */
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

    /**
     * RB-5 · el enlace firmado descarga el archivo; SP-3 · caducado, `403`;
     * SP-4 · purgado, `410` con la explicación.
     */
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

    /**
     * RB-12, SP-9 · una URL firmada válida, abierta por alguien de **otro**
     * negocio, es `403`: la firma no basta sin la organización.
     */
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

    /**
     * SP-12 · bajar a Free conserva las descargas ya generadas hasta que
     * caduquen; solo no se pueden pedir nuevas.
     */
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

    /**
     * El cierre de la función está **en la ruta** (`plan-feature:excel_export`),
     * además de en la acción: con la acción sola, una petición en Free
     * llegaría hasta validar y autorizar antes de saber que no puede.
     */
    public function test_the_route_declares_the_paid_feature_and_a_throttle(): void
    {
        $middleware = app('router')->getRoutes()->getByName('exports.store')?->gatherMiddleware() ?? [];

        $this->assertContains('plan-feature:excel_export', $middleware);
        $this->assertNotEmpty(array_filter($middleware, fn (string $name): bool => str_starts_with($name, 'throttle:')));
    }

    /**
     * Revisión de `T-070` · si ya había una en curso de ese tipo, la pantalla
     * lo dice: no promete «tu archivo» con filtros que no son los suyos.
     */
    public function test_a_reused_export_says_so(): void
    {
        $this->actingAs($this->owner)->post('/backoffice/exportaciones', ['type' => 'collections', 'filters' => ['preset' => 'due_today']]);

        $this->post('/backoffice/exportaciones', ['type' => 'collections', 'filters' => ['preset' => 'overdue_over_15_days']])
            ->assertSessionHas('status', fn (string $status): bool => str_starts_with($status, 'Ya se está generando una exportación de cobranza'));
    }

    /**
     * `410` también para un archivo vencido que espera a la purga de la
     * noche, y para uno que ya no está en el disco (no un `500`); una que aún
     * no tiene archivo es `404`. El enlace caducado, `403` con la salida.
     */
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
