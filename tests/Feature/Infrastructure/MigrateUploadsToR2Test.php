<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class MigrateUploadsToR2Test extends TestCase
{
    use ReusesMigratedDatabase;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = storage_path('framework/testing/uploads-'.uniqid());
        File::ensureDirectoryExists($this->source.'/organizations/7/media/12');
        File::ensureDirectoryExists($this->source.'/logos/7');
        File::put($this->source.'/organizations/7/media/12/silla.jpg', 'foto-de-la-silla');
        File::put($this->source.'/logos/7/logo.png', 'logo');
        File::put($this->source.'/.gitignore', '*');

        config(['filesystems.uploads' => ['public' => $this->source]]);
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->source);

        parent::tearDown();
    }

    public function test_copia_cada_archivo_local_al_disco_nuevo_con_la_misma_ruta_y_salta_los_ocultos(): void
    {
        $this->artisan('uploads:migrate-to-r2')->assertSuccessful();

        $this->assertSame('foto-de-la-silla', Storage::disk('public')->get('organizations/7/media/12/silla.jpg'));
        $this->assertSame('logo', Storage::disk('public')->get('logos/7/logo.png'));
        $this->assertFalse(Storage::disk('public')->exists('.gitignore'));
    }

    public function test_no_vuelve_a_subir_lo_que_ya_esta_con_el_mismo_tamano_salvo_con_force(): void
    {
        Storage::disk('public')->put('logos/7/logo.png', 'otro');

        $this->artisan('uploads:migrate-to-r2')->assertSuccessful();
        $this->assertSame('otro', Storage::disk('public')->get('logos/7/logo.png'));

        $this->artisan('uploads:migrate-to-r2', ['--force' => true])->assertSuccessful();
        $this->assertSame('logo', Storage::disk('public')->get('logos/7/logo.png'));
    }

    public function test_en_simulacion_no_sube_nada(): void
    {
        $this->artisan('uploads:migrate-to-r2', ['--dry-run' => true])
            ->expectsOutputToContain('no se subió nada')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_se_niega_a_correr_si_el_disco_todavia_apunta_a_la_carpeta_local(): void
    {
        config(['filesystems.uploads' => ['public' => Storage::disk('public')->path('')]]);

        $this->artisan('uploads:migrate-to-r2')
            ->expectsOutputToContain('UPLOADS_STORAGE=r2')
            ->assertFailed();
    }

    public function test_rechaza_un_disco_que_no_es_de_archivos_subidos(): void
    {
        $this->artisan('uploads:migrate-to-r2', ['--disk' => ['local']])
            ->expectsOutputToContain('No hay disco de archivos subidos llamado: local')
            ->assertFailed();
    }
}
