<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal\Auth;

use App\Actions\Account\AuthenticateAccountAction;
use App\Actions\Account\GoogleUserData;
use App\Domain\Account\UnverifiedGoogleEmailException;
use App\Http\Controllers\Controller;
use App\Jobs\LinkVisitorEventsToAccount;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;

final class GoogleAccountController extends Controller
{
    public const ORIGIN_KEY = 'account.google.origin';

    public const CONTINUE_KEY = 'account.google.continue';

    public const SCOPES = ['openid', 'email', 'profile'];

    public const CANCELLED_MESSAGE = 'No se inició sesión con Google. Puedes seguir viendo el catálogo y apartar sin cuenta.';

    public const UNAVAILABLE_MESSAGE = 'Ahora mismo no podemos conectar con Google. Puedes seguir viendo el catálogo y apartar sin cuenta; seguir catálogos estará disponible en un rato.';

    public function redirect(Request $request): Response
    {
        $request->session()->put(self::ORIGIN_KEY, $this->safeOrigin($request->query('desde')));

        $continue = $request->query('luego');

        if ($this->isAppPath($continue)) {
            $request->session()->put(self::CONTINUE_KEY, $continue);
        } else {
            $request->session()->forget(self::CONTINUE_KEY);
        }

        $target = $this->google()->redirect()->getTargetUrl();

        return $request->hasHeader('X-Inertia')
            ? Inertia::location($target)
            : redirect()->away($target);
    }

    public function callback(Request $request, AuthenticateAccountAction $authenticate): Response
    {
        $origin = $this->safeOrigin($request->session()->pull(self::ORIGIN_KEY));
        $continue = $request->session()->pull(self::CONTINUE_KEY);

        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->backTo($origin, self::CANCELLED_MESSAGE);
        }

        try {
            $data = GoogleUserData::fromSocialite($this->google()->user());
        } catch (InvalidStateException) {
            return Inertia::render('portal/GoogleSignInExpired', [
                'retryUrl' => route('account.google.redirect', ['desde' => $origin]),
                'backUrl' => $origin,
            ])->toResponse($request)->setStatusCode(419);
        } catch (GuzzleException $exception) {
            report($exception);

            return $this->backTo($origin, self::UNAVAILABLE_MESSAGE);
        } catch (UnverifiedGoogleEmailException $exception) {
            return $this->backTo($origin, $exception->getMessage());
        }

        $account = $authenticate->handle($data);

        LinkVisitorEventsToAccount::dispatchFor($request, $account);

        $request->session()->regenerate();

        return $this->isAppPath($continue) ? redirect()->to($continue) : redirect()->intended($origin);
    }

    private function google(): Provider
    {
        $provider = Socialite::driver('google');

        if ($provider instanceof AbstractProvider) {
            $provider->setScopes(self::SCOPES);
        }

        return $provider;
    }

    private function backTo(string $origin, string $message): RedirectResponse
    {
        return redirect()->to($origin)->with('status', $message);
    }

    private function safeOrigin(mixed $origin): string
    {
        return $this->isAppPath($origin) ? $origin : route('portal.access', absolute: false);
    }

    /**
     * @phpstan-assert-if-true string $path
     */
    private function isAppPath(mixed $path): bool
    {
        return is_string($path) && preg_match('#^/(?![/\\\\])#', $path) === 1 && ! preg_match('/[\x00-\x1F]/', $path);
    }
}
