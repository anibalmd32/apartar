<?php

declare(strict_types=1);

namespace Tests\Feature\Backoffice;

use App\Ai\Agents\ProductCopywriter;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class ProductCopyEndpointTest extends TestCase
{
    use ReusesMigratedDatabase;

    private const URL = '/backoffice/productos/ia/sugerencia';

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        config(['ai.providers.deepseek.key' => 'clave-de-prueba', 'ai.daily_limit_per_organization' => 30]);

        $this->neighbourOrganization();
        $this->organization = Organization::factory()->create();
        $this->subscribeWithoutLimits($this->organization);
        $this->withoutOrganization();

        $this->withHeaders(['Accept' => 'application/json']);
    }

    public function test_it_answers_the_suggestion_as_json(): void
    {
        ProductCopywriter::fake([[
            'description_html' => '<p>Una silla <strong>cómoda</strong>.</p><script>x</script>',
            'characteristics' => ['Color gris', 'Color gris', 'Patas de madera'],
        ]]);

        $this->actingAs($this->owner())
            ->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla', 'category_name' => 'Hogar', 'images' => [$this->image('silla.png')]])
            ->assertOk()
            ->assertExactJson([
                'description' => '<p>Una silla <strong>cómoda</strong>.</p>',
                'characteristics' => ['Color gris', 'Patas de madera'],
                'remaining' => 29,
            ]);

        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1 && $prompt->contains('Hogar'));
    }

    public function test_an_existing_product_lends_its_stored_photos(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create());

        $this->actingAs($this->owner())
            ->post("/backoffice/productos/{$product->getKey()}/imagenes", ['images' => [$this->image('guardada.png')]])
            ->assertSessionHasNoErrors();

        ProductCopywriter::fake([['description_html' => '<p>Texto</p>', 'characteristics' => []]]);

        $this->actingAs($this->owner())
            ->post(self::URL, ['mode' => 'suggest', 'product_id' => $product->getKey()])
            ->assertOk();

        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 1);
    }

    public function test_a_product_of_another_organization_is_not_found(): void
    {
        $foreign = $this->withinOrganization($this->neighbourOrganization(), fn (): Product => Product::factory()->create());

        ProductCopywriter::fake();

        $this->actingAs($this->owner())
            ->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla', 'product_id' => $foreign->getKey()])
            ->assertNotFound();

        ProductCopywriter::assertNeverPrompted();
    }

    public function test_the_input_is_validated_in_spanish(): void
    {
        ProductCopywriter::fake();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'translate', 'name' => 'Silla'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mode');

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'improve', 'name' => 'Silla'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description' => 'Escribe primero una descripción para que la IA la mejore.']);

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'suggest'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name' => 'Escribe el nombre o agrega una foto para que la IA tenga de dónde partir.']);

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'suggest', 'images' => array_map(fn (int $n): UploadedFile => $this->image("{$n}.png"), range(1, 5))])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['images' => 'La IA mira como máximo 4 fotos.']);

        ProductCopywriter::assertNeverPrompted();
    }

    public function test_the_exhausted_quota_answers_429(): void
    {
        config(['ai.daily_limit_per_organization' => 1]);
        ProductCopywriter::fake(fn (): array => ['description_html' => '<p>Texto</p>', 'characteristics' => []]);
        $owner = $this->owner();

        $this->actingAs($owner)->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla'])->assertOk()->assertJsonPath('remaining', 0);

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla'])
            ->assertStatus(429)
            ->assertJson(['message' => 'Ya usaste la única sugerencia de hoy. Vuelve mañana.', 'remaining' => 0]);
    }

    public function test_a_provider_failure_answers_503(): void
    {
        ProductCopywriter::fake(function (): never {
            throw new RuntimeException('timeout');
        });

        $this->actingAs($this->owner())
            ->post(self::URL, ['mode' => 'improve', 'name' => 'Silla', 'description' => '<p>Cómoda.</p>'])
            ->assertStatus(503)
            ->assertJson(['message' => 'La IA no respondió. Intenta de nuevo en un momento.', 'remaining' => 30]);
    }

    public function test_without_a_key_it_answers_503_and_the_form_says_so(): void
    {
        config(['ai.providers.deepseek.key' => null]);
        ProductCopywriter::fake();
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla'])
            ->assertStatus(503)
            ->assertJson(['message' => 'La IA todavía no está configurada.', 'remaining' => null]);

        $this->actingAs($owner)
            ->get('/backoffice/productos/crear')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ai.available', false)
                ->where('ai.remaining', null));
    }

    public function test_the_form_carries_the_availability_and_the_remaining_suggestions(): void
    {
        $product = $this->inTenant(fn (): Product => Product::factory()->create());

        $this->actingAs($this->owner())
            ->get("/backoffice/productos/{$product->getKey()}/editar")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ai.available', true)
                ->where('ai.remaining', 30));
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->post(self::URL, ['mode' => 'suggest', 'name' => 'Silla'])->assertUnauthorized();
    }

    private function image(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function owner(): User
    {
        return $this->withinOrganization($this->organization, fn (): User => User::factory()->owner()->create());
    }

    /**
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(\Closure $callback): mixed
    {
        return $this->withinOrganization($this->organization, $callback);
    }
}
