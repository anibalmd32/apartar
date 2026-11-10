<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\ConfirmHeldCopyAction;
use App\Domain\Catalog\CopywriterUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductCopyConfirmationController extends Controller
{
    public function __invoke(Request $request, ConfirmHeldCopyAction $confirm): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:64']]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            return response()->json($confirm->handle($actor, (string) $data['token']));
        } catch (CopywriterUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }
    }
}
