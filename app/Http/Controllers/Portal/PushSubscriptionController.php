<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Account\PortalContext;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;

final class PushSubscriptionController extends Controller
{
    public const UNAVAILABLE_MESSAGE = 'Los avisos en el teléfono no están disponibles ahora mismo.';

    public function store(Request $request, PortalContext $portal): JsonResponse
    {
        if (! self::isConfigured()) {
            return response()->json(['message' => self::UNAVAILABLE_MESSAGE], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);

        $this->notifiable($portal)->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aes128gcm',
        );

        return response()->json(null, 201);
    }

    public function destroy(Request $request, PortalContext $portal): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'url', 'max:500']]);

        $this->notifiable($portal)->deletePushSubscription($data['endpoint']);

        return response()->json(null, 204);
    }

    public static function isConfigured(): bool
    {
        return filled(config('webpush.vapid.public_key')) && filled(config('webpush.vapid.private_key'));
    }

    private function notifiable(PortalContext $portal): Account|Customer
    {
        return $portal->account ?? $portal->scopedCustomer ?? throw new LogicException('PushSubscriptionController va detrás de `portal`.');
    }
}
