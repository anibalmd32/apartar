<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Product\SuggestProductCopyAction;
use App\Domain\Catalog\CopywriterUnavailableException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\SuggestProductCopyRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class ProductCopyController extends Controller
{
    public function __invoke(SuggestProductCopyRequest $request, SuggestProductCopyAction $copywriter): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $copy = $copywriter->handle(
                $actor,
                $request->mode(),
                $request->productName(),
                $request->categoryName(),
                $request->description(),
                $request->images(),
                $request->product(),
            );
        } catch (CopywriterUnavailableException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'remaining' => $copywriter->remaining(),
            ], $exception->status);
        }

        return response()->json($copy);
    }
}
