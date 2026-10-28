<?php

declare(strict_types=1);

namespace App\Actions\Product;

use App\Ai\Agents\ProductCopywriter;
use App\Domain\Catalog\CopywriterUnavailableException;
use App\Domain\Catalog\CopywritingMode;
use App\Domain\Catalog\ProductDescription;
use App\Domain\Tenancy\TenantContext;
use App\Models\Product;
use App\Models\Tag;
use App\Models\User;
use App\Support\Ai\CopywriterImages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class SuggestProductCopyAction
{
    public const MAX_CHARACTERISTICS = 8;

    public const MAX_CHARACTERISTIC_LENGTH = 200;

    public const MAX_TAGS = 6;

    public const MAX_TAG_LENGTH = 40;

    private const KNOWN_TAGS_IN_PROMPT = 60;

    private const QUOTA_SECONDS = 86_400;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CopywriterImages $images,
    ) {}

    public function available(): bool
    {
        $provider = config('ai.default');

        return is_string($provider) && filled(config("ai.providers.{$provider}.key"));
    }

    public function remaining(): ?int
    {
        if (! $this->available()) {
            return null;
        }

        return max(0, $this->limit() - RateLimiter::attempts($this->quotaKey()));
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return array{description: string, characteristics: list<string>, tags: list<string>, remaining: int}
     *
     * @throws AuthorizationException
     * @throws CopywriterUnavailableException
     */
    public function handle(
        User $actor,
        CopywritingMode $mode,
        string $name,
        ?string $categoryName = null,
        ?string $description = null,
        array $uploads = [],
        ?Product $product = null,
    ): array {
        Gate::forUser($actor)->authorize('manageProducts', $this->context->organization());

        if (! $this->available()) {
            throw CopywriterUnavailableException::notConfigured();
        }

        if (RateLimiter::tooManyAttempts($this->quotaKey(), $this->limit())) {
            throw CopywriterUnavailableException::quotaExhausted($this->limit());
        }

        $copy = $this->cleaned($this->ask($mode, $this->prompt($name, $categoryName, $description), $uploads, $product));

        RateLimiter::hit($this->quotaKey(), self::QUOTA_SECONDS);

        return $copy + ['remaining' => (int) $this->remaining()];
    }

    /**
     * @param  list<UploadedFile>  $uploads
     * @return array<mixed>
     *
     * @throws CopywriterUnavailableException
     */
    private function ask(CopywritingMode $mode, string $prompt, array $uploads, ?Product $product): array
    {
        try {
            return (new ProductCopywriter($mode))->prompt($prompt, $this->images->prepare($uploads, $product))->toArray();
        } catch (Throwable $exception) {
            Log::warning('La IA no pudo sugerir el texto de un producto.', [
                'organization_id' => $this->context->organizationId(),
                'mode' => $mode->value,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            throw CopywriterUnavailableException::providerFailed($exception);
        } finally {
            $this->images->release();
        }
    }

    /**
     * @param  array<mixed>  $answer
     * @return array{description: string, characteristics: list<string>, tags: list<string>}
     *
     * @throws CopywriterUnavailableException
     */
    private function cleaned(array $answer): array
    {
        $html = $answer['description_html'] ?? null;
        $description = is_string($html) ? ProductDescription::sanitize($html) : '';

        if ($description === '') {
            Log::warning('La IA respondió sin una descripción utilizable.', ['organization_id' => $this->context->organizationId()]);

            throw CopywriterUnavailableException::providerFailed();
        }

        $items = $answer['characteristics'] ?? [];
        $tags = $answer['tags'] ?? [];

        return [
            'description' => $description,
            'characteristics' => $this->listOf(is_array($items) ? $items : [], self::MAX_CHARACTERISTICS, self::MAX_CHARACTERISTIC_LENGTH),
            'tags' => $this->tags(is_array($tags) ? $tags : []),
        ];
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private function tags(array $items): array
    {
        $known = [];

        foreach ($this->knownTags() as $name) {
            $known[mb_strtolower($name)] = $name;
        }

        $clean = array_map(
            fn (string $tag): string => $known[mb_strtolower($tag)] ?? $tag,
            $this->listOf(array_map(fn (mixed $tag): mixed => is_string($tag) ? ltrim($tag, '#') : $tag, $items), self::MAX_TAGS, self::MAX_TAG_LENGTH),
        );

        return array_values(array_unique($clean));
    }

    /**
     * @return list<string>
     */
    private function knownTags(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->limit(self::KNOWN_TAGS_IN_PROMPT)
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->values()
            ->all();
    }

    /**
     * @param  array<mixed>  $items
     * @return list<string>
     */
    private function listOf(array $items, int $max, int $maxLength): array
    {
        $clean = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $text = html_entity_decode(strip_tags($item), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
            $text = rtrim(ltrim($text, "-•*· \t"), '. ');
            $text = mb_substr($text, 0, $maxLength);

            $signature = mb_strtolower($text);

            if ($text === '' || array_key_exists($signature, $clean)) {
                continue;
            }

            $clean[$signature] = $text;

            if (count($clean) === $max) {
                break;
            }
        }

        return array_values($clean);
    }

    private function prompt(string $name, ?string $categoryName, ?string $description): string
    {
        $current = ProductDescription::sanitize((string) $description);

        return implode("\n", [
            'Nombre del producto: '.(trim($name) === '' ? '(sin nombre todavía)' : trim($name)),
            'Categoría: '.(blank($categoryName) ? '(sin categoría)' : trim((string) $categoryName)),
            'Descripción actual (HTML): '.($current === '' ? '(vacía)' : $current),
            'Etiquetas que ya usa el negocio: '.($this->knownTags() === [] ? '(ninguna)' : implode(', ', $this->knownTags())),
        ]);
    }

    private function limit(): int
    {
        return max(0, config()->integer('ai.daily_limit_per_organization', 30));
    }

    private function quotaKey(): string
    {
        $organization = $this->context->organization();

        return sprintf('ai-copy:%d:%s', $organization->getKey(), now($organization->timezone)->toDateString());
    }
}
