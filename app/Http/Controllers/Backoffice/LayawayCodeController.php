<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Customer\ResendLayawayCodeAction;
use App\Domain\Customer\NoEmailToResendException;
use App\Http\Controllers\Controller;
use App\Models\Layaway;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Reenviar el código de un apartado al correo de su cliente (`REQ-IDN-008`
 * SP-5, `REQ-BOF-041` RB-5). Sin correo, vuelve con el aviso de copiarlo y
 * dictarlo (SP-7).
 */
final class LayawayCodeController extends Controller
{
    public function __invoke(Request $request, Layaway $layaway, ResendLayawayCodeAction $resend): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $email = $resend->handle($layaway, $actor);
        } catch (NoEmailToResendException $exception) {
            return back()->withErrors(['resend' => $exception->getMessage()]);
        }

        return back()->with('status', sprintf('Código %s reenviado a %s.', $layaway->code, $email));
    }
}
