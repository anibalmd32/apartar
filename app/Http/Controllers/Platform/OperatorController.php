<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Actions\Platform\CreatePlatformOperatorAction;
use App\Actions\Platform\ResetPlatformOperatorPasswordAction;
use App\Actions\Platform\SetPlatformOperatorAccessAction;
use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class OperatorController extends Controller
{
    public function store(Request $request, CreatePlatformOperatorAction $create): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190'],
        ]);

        [$operator, $password] = $this->rethrow('email', fn (): array => $create->handle($data['name'], $data['email'], $this->actor($request)));

        return back()
            ->with('status', 'Operador creado. Comparte su contraseña temporal por un canal seguro.')
            ->with('temporaryPassword', ['name' => $operator->name, 'email' => $operator->email, 'password' => $password]);
    }

    public function access(Request $request, PlatformUser $operator, SetPlatformOperatorAccessAction $access): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $this->rethrow('operator', fn (): PlatformUser => $access->handle($operator, (bool) $active, $this->actor($request)));

        return back()->with('status', $active ? 'Operador reactivado.' : 'Operador desactivado: pierde la sesión en su siguiente petición.');
    }

    public function resetPassword(Request $request, PlatformUser $operator, ResetPlatformOperatorPasswordAction $reset): RedirectResponse
    {
        $password = $this->rethrow('operator', fn (): string => $reset->handle($operator, $this->actor($request)));

        return back()
            ->with('status', 'Contraseña restablecida. La cambiará al entrar.')
            ->with('temporaryPassword', ['name' => $operator->name, 'email' => $operator->email, 'password' => $password]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function rethrow(string $field, Closure $action): mixed
    {
        try {
            return $action();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }

    private function actor(Request $request): PlatformUser
    {
        $user = $request->user('platform');

        return $user instanceof PlatformUser ? $user : abort(401);
    }
}
