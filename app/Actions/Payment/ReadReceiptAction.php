<?php

declare(strict_types=1);

namespace App\Actions\Payment;

use App\Ai\Agents\ReceiptReader;
use App\Domain\Payment\ReceiptReading;
use App\Domain\Tenancy\TenantContext;
use App\Support\Ai\CopywriterImages;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class ReadReceiptAction
{
    public const READABLE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const DAILY_READS_PER_ORGANIZATION = 300;

    private const QUOTA_SECONDS = 86_400;

    public function __construct(
        private readonly TenantContext $context,
        private readonly CopywriterImages $images,
    ) {}

    public function handle(UploadedFile $file, string $earliest, string $latest): ReceiptReading
    {
        if (! $this->available() || ! in_array($file->getMimeType(), self::READABLE_TYPES, true)) {
            return ReceiptReading::nothing();
        }

        if (RateLimiter::tooManyAttempts($this->quotaKey(), self::DAILY_READS_PER_ORGANIZATION)) {
            return ReceiptReading::nothing();
        }

        RateLimiter::hit($this->quotaKey(), self::QUOTA_SECONDS);

        try {
            $answer = (new ReceiptReader)->prompt($this->prompt($earliest, $latest), $this->images->prepare([$file], null))->toArray();
        } catch (Throwable $exception) {
            Log::warning('La IA no pudo leer un comprobante.', [
                'organization_id' => $this->context->organizationId(),
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ReceiptReading::nothing();
        } finally {
            $this->images->release();
        }

        return ReceiptReading::fromAnswer($answer, $earliest, $latest);
    }

    public function available(): bool
    {
        $provider = config('ai.default');

        return is_string($provider) && filled(config("ai.providers.{$provider}.key"));
    }

    private function prompt(string $earliest, string $latest): string
    {
        return sprintf('Lee este comprobante. La fecha del pago está entre el %s y el %s.', $earliest, $latest);
    }

    private function quotaKey(): string
    {
        $organization = $this->context->organization();

        return sprintf('ai-receipt:%d:%s', $organization->getKey(), now($organization->timezone)->toDateString());
    }
}
