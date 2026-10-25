<?php

declare(strict_types=1);

namespace App\Http\Controllers\Catalog;

use App\Actions\Account\SubscribeToCatalogAction;
use App\Actions\Account\UnsubscribeFromCatalogAction;
use App\Domain\Account\CatalogNotSubscribableException;
use App\Domain\Account\FollowIntent;
use App\Domain\Tenancy\TenantResolver;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Catalog;
use App\Support\CatalogShareLink;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class CatalogSubscriptionController extends Controller
{
    public const FOLLOWING_MESSAGE = 'Ahora sigues «%s». Verás sus novedades en tu cuenta.';

    public function store(Request $request, Catalog $catalog, SubscribeToCatalogAction $subscribe): Response
    {
        $account = Auth::guard('account')->user();

        if (! $catalog->visibility()->isPubliclyAccessible()) {
            throw ValidationException::withMessages(['follow' => CatalogNotSubscribableException::MESSAGE]);
        }

        if (! $account instanceof Account) {
            FollowIntent::remember($request->session(), $catalog, CarbonImmutable::now());

            $target = route('account.google.redirect', [
                'desde' => $this->pathOf($catalog->slug),
                'luego' => route('account.follow.complete', absolute: false),
            ]);

            return $request->hasHeader('X-Inertia') ? Inertia::location($target) : redirect()->to($target);
        }

        $subscribe->handle($account, $catalog);

        return back();
    }

    public function destroy(Catalog $catalog, UnsubscribeFromCatalogAction $unsubscribe): RedirectResponse
    {
        $account = Auth::guard('account')->user();

        if ($account instanceof Account) {
            $unsubscribe->handle($account, (int) $catalog->getKey());
        }

        return back();
    }

    public function complete(Request $request, SubscribeToCatalogAction $subscribe, TenantResolver $resolver): RedirectResponse
    {
        $intent = FollowIntent::pull($request->session(), CarbonImmutable::now());
        $account = Auth::guard('account')->user();

        if (! $account instanceof Account) {
            return redirect()->route('home');
        }

        try {
            $catalog = $intent === null ? null : $resolver->publicCatalog($intent->catalogSlug);
        } catch (ModelNotFoundException) {
            $catalog = null;
        }

        if ($intent === null || $catalog === null || ! $intent->isFor($catalog)) {
            return redirect()->route('portal.subscriptions');
        }

        try {
            $subscribe->handle($account, $catalog);
        } catch (CatalogNotSubscribableException $exception) {
            return redirect()->to($this->pathOf($catalog->slug))->with('status', $exception->getMessage());
        }

        return redirect()->to($this->pathOf($catalog->slug))->with('status', sprintf(self::FOLLOWING_MESSAGE, $catalog->name));
    }

    private function pathOf(string $slug): string
    {
        return (string) parse_url(CatalogShareLink::of($slug), PHP_URL_PATH);
    }
}
