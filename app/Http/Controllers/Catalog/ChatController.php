<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Chat\SendMessageAction;
use App\Domain\Account\PortalContext;
use App\Domain\Chat\MessageSenderType;
use App\Domain\Chat\NoCustomerRecordException;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Models\Catalog;
use Illuminate\Http\JsonResponse;

final class ChatController extends Controller
{
    public function store(SendMessageRequest $request, Catalog $catalog, PortalContext $portal, TenantContext $context, SendMessageAction $send): JsonResponse
    {
        $organization = $context->organization();
        $customer = $portal->customerIn((int) $organization->getKey()) ?? throw new NoCustomerRecordException;

        $message = $send->handle($organization, $customer, MessageSenderType::Customer, (int) $customer->getKey(), $request->body(), $request->idempotencyKey());

        return response()->json([
            'id' => $message->getKey(),
            'threadUrl' => route('portal.chat.show', ['organization' => $organization->slug]),
        ], 201);
    }
}
