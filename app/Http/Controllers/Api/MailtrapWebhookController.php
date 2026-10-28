<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Mail\MailtrapPayload;
use App\Domain\Mail\MailtrapSignature;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessMailtrapEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MailtrapWebhookController extends Controller
{
    public const MAX_BODY_BYTES = 5_242_880;

    public function sending(Request $request): Response
    {
        $events = $this->verifiedEvents($request, 'services.mailtrap.sending_webhook_secret');

        if ($events instanceof Response) {
            return $events;
        }

        if ($events !== []) {
            ProcessMailtrapEvents::dispatch($events);
        }

        return response()->noContent(200);
    }

    /**
     * @return list<array<string, mixed>>|Response
     */
    private function verifiedEvents(Request $request, string $secretKey): array|Response
    {
        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return response()->noContent(413);
        }

        $secret = config($secretKey);

        if (! MailtrapSignature::verify($body, $request->header(MailtrapSignature::HEADER), is_string($secret) ? $secret : null)) {
            return response()->noContent(401);
        }

        return MailtrapPayload::events($body) ?? response()->noContent(400);
    }
}
