<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\ReviewProductPhotosAction;
use App\Domain\Catalog\PhotoReviewAlreadyClosedException;
use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Queries\Platform\ProductPhotoReviewInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class ProductPhotoReviewController extends Controller
{
    public function index(ProductPhotoReviewInbox $inbox): Response
    {
        return Inertia::render('platform/reviews/Index', ['reviews' => $inbox->pending()]);
    }

    public function approve(Request $request, int $review, ProductPhotoReviewInbox $inbox, ReviewProductPhotosAction $reviews): RedirectResponse
    {
        $found = $inbox->find($review) ?? abort(404);

        try {
            $reviews->approve($found, $this->operator($request));
        } catch (PhotoReviewAlreadyClosedException $exception) {
            throw ValidationException::withMessages(['review' => $exception->getMessage()]);
        }

        return back()->with('status', 'Fotos aprobadas. El negocio ya puede publicar el producto.');
    }

    public function reject(Request $request, int $review, ProductPhotoReviewInbox $inbox, ReviewProductPhotosAction $reviews): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:'.ReviewProductPhotosAction::REASON_MAX]]);
        $found = $inbox->find($review) ?? abort(404);

        try {
            $reviews->reject($found, (string) $data['reason'], $this->operator($request));
        } catch (PhotoReviewAlreadyClosedException|InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['reason' => $exception->getMessage()]);
        }

        return back()->with('status', 'Fotos rechazadas. El producto queda en borrador.');
    }

    private function operator(Request $request): PlatformUser
    {
        $operator = $request->user('platform');

        abort_unless($operator instanceof PlatformUser, 403);

        return $operator;
    }
}
