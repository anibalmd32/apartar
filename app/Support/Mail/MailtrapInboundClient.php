<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MailtrapInboundClient
{
    public const MAX_ATTACHMENT_BYTES = 26_214_400;

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function message(int $inboxId, string $messageId): array
    {
        $token = config('services.mailtrap.api_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Falta MAILTRAP_API_TOKEN para leer los correos entrantes.');
        }

        $url = sprintf('%s/api/inbound/inboxes/%d/messages/%s', rtrim((string) config('services.mailtrap.api_url'), '/'), $inboxId, rawurlencode($messageId));
        $data = Http::withToken($token)->acceptJson()->timeout($this->timeout())->retry(2, 500, throw: false)->get($url)->throw()->json();

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Mailtrap devolvió un correo entrante ilegible.');
        }

        return $data;
    }

    /**
     * @throws RequestException
     */
    public function download(string $url): string
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('El enlace del adjunto no es seguro.');
        }

        $body = Http::timeout($this->timeout() * 4)->get($url)->throw()->body();

        if (strlen($body) > self::MAX_ATTACHMENT_BYTES) {
            throw new RuntimeException('El adjunto supera el tamaño máximo.');
        }

        return $body;
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.mailtrap.timeout', 15));
    }
}
