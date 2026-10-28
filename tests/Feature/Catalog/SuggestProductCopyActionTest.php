<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Actions\Product\AttachProductImagesAction;
use App\Actions\Product\SuggestProductCopyAction;
use App\Ai\Agents\ProductCopywriter;
use App\Domain\Catalog\CopywriterUnavailableException;
use App\Domain\Catalog\CopywritingMode;
use App\Jobs\TranscodeProductImage;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Concerns\ReusesMigratedDatabase;
use Tests\TestCase;

final class SuggestProductCopyActionTest extends TestCase
{
    use ReusesMigratedDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([TranscodeProductImage::class]);

        config(['ai.providers.deepseek.key' => 'clave-de-prueba', 'ai.daily_limit_per_organization' => 3]);

        $this->neighbourOrganization();
        $this->actingAsOrganization(Organization::factory()->create());
        $this->subscribeWithoutLimits();

        $this->actor = User::factory()->owner()->create();
    }

    public function test_it_suggests_a_clean_description_from_the_name_and_the_photos(): void
    {
        ProductCopywriter::fake([$this->answer()]);

        $copy = $this->copywriter()->handle(
            $this->actor,
            CopywritingMode::Suggest,
            'Licuadora Oster',
            'Cocina',
            null,
            [$this->image('frente.png'), $this->image('lado.png')],
        );

        $this->assertSame('<p>Una licuadora <strong>potente</strong>.</p>', $copy['description']);
        $this->assertSame(['Color negro', 'Vaso de vidrio'], $copy['characteristics']);
        $this->assertSame(2, $copy['remaining']);

        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Licuadora Oster')
            && $prompt->contains('Cocina')
            && $prompt->attachments->count() === 2
            && str_contains((string) $prompt->agent->instructions(), 'escribe una descripción nueva'));
    }

    public function test_improving_keeps_the_written_description_in_the_prompt(): void
    {
        ProductCopywriter::fake([$this->answer()]);

        $this->copywriter()->handle(
            $this->actor,
            CopywritingMode::Improve,
            'Licuadora',
            null,
            '<p>Tiene 3 velocidades y vaso de 1,5 litros.</p>',
        );

        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('3 velocidades')
            && $prompt->contains('(sin categoría)')
            && $prompt->attachments->isEmpty()
            && str_contains((string) $prompt->agent->instructions(), 'Conserva todos y cada uno de los datos'));
    }

    public function test_the_returned_html_is_sanitized(): void
    {
        ProductCopywriter::fake([[
            'description_html' => '<h2>Oferta</h2><script>alert(1)</script><p onclick="x()">Mira <a href="https://x.test">esto</a></p>',
            'characteristics' => [],
        ]]);

        $copy = $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Licuadora');

        $this->assertSame('<p>Oferta</p><p>Mira esto</p>', $copy['description']);
        $this->assertSame([], $copy['characteristics']);
    }

    public function test_the_characteristics_are_trimmed_deduplicated_and_capped(): void
    {
        ProductCopywriter::fake([[
            'description_html' => '<p>Texto</p>',
            'characteristics' => [
                '  Color   azul marino. ',
                'color azul marino',
                '<b>Talla M</b>',
                '- Algodón',
                42,
                '',
                str_repeat('x', 300),
                'Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis',
            ],
        ]]);

        $copy = $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Franela');

        $this->assertSame(
            ['Color azul marino', 'Talla M', 'Algodón', str_repeat('x', 200), 'Uno', 'Dos', 'Tres', 'Cuatro'],
            $copy['characteristics'],
        );
    }

    public function test_the_tags_reuse_the_ones_the_business_already_has_and_are_capped(): void
    {
        Tag::factory()->create(['name' => 'Mochilas']);

        ProductCopywriter::fake([[
            'description_html' => '<p>Texto</p>',
            'characteristics' => [],
            'tags' => ['mochilas', '#viaje', 'viaje', 'impermeable', 'urbano', 'escolar', 'deporte', 'extra'],
        ]]);

        $copy = $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Mochila');

        $this->assertSame(['Mochilas', 'viaje', 'impermeable', 'urbano', 'escolar', 'deporte'], $copy['tags']);
        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('Etiquetas que ya usa el negocio: Mochilas'));
    }

    public function test_it_sends_at_most_four_photos_mixing_uploads_and_stored_ones(): void
    {
        $product = Product::factory()->create();
        app(AttachProductImagesAction::class)->handle($product, [$this->image('guardada-1.png'), $this->image('guardada-2.png')]);

        ProductCopywriter::fake([$this->answer(), $this->answer()]);

        $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Silla', product: $product->fresh());
        $this->copywriter()->handle(
            $this->actor,
            CopywritingMode::Suggest,
            'Silla',
            uploads: [$this->image('1.png'), $this->image('2.png'), $this->image('3.png')],
            product: $product->fresh(),
        );

        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 2);
        ProductCopywriter::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->attachments->count() === 4);
    }

    public function test_the_daily_quota_is_counted_per_organization(): void
    {
        config(['ai.daily_limit_per_organization' => 2]);
        ProductCopywriter::fake(fn (): array => $this->answer());

        $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Uno');
        $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Dos');

        $this->assertSame(0, $this->copywriter()->remaining());

        try {
            $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Tres');
            $this->fail('El tercer pedido del día debía rechazarse.');
        } catch (CopywriterUnavailableException $exception) {
            $this->assertSame(429, $exception->status);
            $this->assertSame('Ya usaste las 2 sugerencias de hoy. Vuelve mañana.', $exception->getMessage());
        }

        ProductCopywriter::assertPromptedTimes(2);

        $this->actingAsOrganization($this->neighbourOrganization());
        $this->subscribeWithoutLimits();
        $neighbour = User::factory()->owner()->create();

        $this->assertSame(2, $this->copywriter()->remaining());
        $this->assertSame(1, $this->copywriter()->handle($neighbour, CopywritingMode::Suggest, 'Cuatro')['remaining']);
    }

    public function test_a_provider_failure_becomes_a_friendly_error_and_spends_no_quota(): void
    {
        ProductCopywriter::fake(function (): never {
            throw new RuntimeException('cURL error 28: Operation timed out');
        });

        try {
            $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Licuadora');
            $this->fail('Debía traducirse el fallo del proveedor.');
        } catch (CopywriterUnavailableException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('La IA no respondió. Intenta de nuevo en un momento.', $exception->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $exception->getPrevious());
        }

        $this->assertSame(3, $this->copywriter()->remaining());
    }

    public function test_an_answer_without_a_usable_description_is_a_failure(): void
    {
        ProductCopywriter::fake([['description_html' => '<p>   </p>', 'characteristics' => ['Rojo']]]);

        $this->expectExceptionObject(CopywriterUnavailableException::providerFailed());

        $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Licuadora');
    }

    public function test_without_an_api_key_the_feature_is_unavailable(): void
    {
        config(['ai.providers.deepseek.key' => null]);
        ProductCopywriter::fake([$this->answer()]);

        $this->assertFalse($this->copywriter()->available());
        $this->assertNull($this->copywriter()->remaining());

        try {
            $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Licuadora');
            $this->fail('Sin clave no debía llamarse al proveedor.');
        } catch (CopywriterUnavailableException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('La IA todavía no está configurada.', $exception->getMessage());
        }

        ProductCopywriter::assertNeverPrompted();
    }

    public function test_the_deepseek_request_carries_the_model_the_json_format_and_the_photos(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.deepseek.com/*' => Http::response([
            'model' => 'deepseek-flash',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode($this->answer())],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10],
        ])]);

        $copy = $this->copywriter()->handle($this->actor, CopywritingMode::Suggest, 'Licuadora', uploads: [$this->image('foto.png')]);

        $this->assertSame(['Color negro', 'Vaso de vidrio'], $copy['characteristics']);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $images = array_filter(
                data_get($body, 'messages.*.content.*.image_url.url', []),
                fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'data:image/png;base64,'),
            );

            return $request->hasHeader('Authorization', 'Bearer clave-de-prueba')
                && $body['model'] === 'deepseek-flash'
                && $body['response_format'] === ['type' => 'json_object']
                && count($images) === 1;
        });
    }

    private function copywriter(): SuggestProductCopyAction
    {
        return app(SuggestProductCopyAction::class);
    }

    /**
     * @return array{description_html: string, characteristics: list<string>}
     */
    private function answer(): array
    {
        return [
            'description_html' => '<p>Una licuadora <strong>potente</strong>.</p>',
            'characteristics' => ['Color negro', 'Vaso de vidrio'],
        ];
    }

    private function image(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(4, 4);
        $path = sys_get_temp_dir().'/'.uniqid('apartar-', true).'-'.$name;

        imagepng($image, $path);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
