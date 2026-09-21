<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Domain\Catalog\PublishedCatalogs;
use App\Models\Catalog;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Publicar un catálogo cierra el enlace del negocio (`REQ-ORG-006` SP-5).
 *
 * ## Por qué merece su propio archivo
 *
 * La regla se escribió en `T-014` y **nunca se había podido recorrer entera**.
 * `UpdateOrganizationActionTest` la prueba desde hace veinte tareas
 * sustituyendo `PublishedCatalogs` en el contenedor por uno que responde que
 * sí: eso demuestra que la acción pregunta y bloquea, y es todo lo que se
 * podía demostrar mientras nadie pudiera publicar.
 *
 * Lo que **no** demostraba —y es justo lo que se rompe en silencio— es que el
 * `true` llegue a producirse: `PublishedCatalogs::exist()` consulta de verdad
 * desde `T-024`, pero hasta `T-032` no existía ninguna ruta capaz de escribir
 * el `published_at` que esa consulta lee. Una regla que solo se prueba con su
 * colaborador sustituido pasa en verde aunque el colaborador real no responda
 * nunca que sí; el síntoma no aparecería en la suite sino tres días después de
 * que un negocio renombrara su enlace y dejaran de llegarle clientes.
 *
 * Así que esto va por HTTP de punta a punta, con las dos acciones de verdad y
 * sin sustituir nada: se publica una campaña por su ruta, y después el
 * formulario de identidad recibe `422` al intentar cambiar el enlace del
 * negocio.
 */
final class PublishedCatalogLocksTheBusinessSlugTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create(['slug' => 'ferreteria-el-tornillo']);

        $this->withoutOrganization();
    }

    public function test_publishing_a_catalog_closes_the_business_link_for_good(): void
    {
        $owner = $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());

        $catalog = $this->withinOrganization($this->organization, function (): Catalog {
            PaymentAccount::factory()->create();

            $catalog = Catalog::factory()->create(['name' => 'Ofertas de septiembre']);
            $catalog->products()->attach(Product::factory()->published()->create(), ['position' => 1]);

            return $catalog;
        });

        // Antes de publicar, el negocio todavía puede renombrar su enlace: la
        // vitrina no ha circulado.
        $this->assertFalse($this->withinOrganization($this->organization, fn (): bool => app(PublishedCatalogs::class)->exist()));

        $this->actingAs($owner)
            ->put('/backoffice/ajustes/identidad', $this->identity('ferreteria-tornillo'))
            ->assertSessionHasNoErrors();

        $this->assertSame('ferreteria-tornillo', $this->organization->refresh()->slug);

        // Y ahora sí: la campaña sale a la calle por su propia ruta.
        $this->actingAs($owner)
            ->post("/backoffice/catalogos/{$catalog->getKey()}/publicar", ['is_listed' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($catalog->refresh()->published_at);
        $this->assertTrue($this->withinOrganization($this->organization, fn (): bool => app(PublishedCatalogs::class)->exist()));

        // SP-5 · desde aquí el enlace del negocio está cerrado, y el `422`
        // aterriza sobre el campo con el motivo.
        $this->actingAs($owner)
            ->from('/backoffice/ajustes')
            ->put('/backoffice/ajustes/identidad', $this->identity('otro-nombre'))
            ->assertSessionHasErrors('slug');

        $this->assertSame('ferreteria-tornillo', $this->organization->refresh()->slug);

        // Lo demás del formulario sigue guardándose: el cerrojo es del enlace,
        // no de la pantalla.
        $this->actingAs($owner)
            ->put('/backoffice/ajustes/identidad', $this->identity('ferreteria-tornillo', 'Ferretería El Tornillo, C.A.'))
            ->assertSessionHasNoErrors();

        $this->assertSame('Ferretería El Tornillo, C.A.', $this->organization->refresh()->name);
    }

    /**
     * El formulario de identidad entero: manda todos sus campos en cada
     * guardado, así que el test también.
     *
     * @return array<string, string>
     */
    private function identity(string $slug, string $name = 'Ferretería El Tornillo'): array
    {
        return [
            '_method' => 'put',
            'name' => $name,
            'slug' => $slug,
            'document' => 'J-12345678-9',
            'phone' => '0412-1234567',
            'timezone' => 'America/Caracas',
            'brand_color' => '#1d4ed8',
            'storefront_enabled' => '1',
        ];
    }
}
