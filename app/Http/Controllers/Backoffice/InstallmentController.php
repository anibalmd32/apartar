<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backoffice;

use App\Actions\Layaway\RescheduleInstallmentAction;
use App\Domain\Layaway\InvalidRescheduleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backoffice\RescheduleInstallmentRequest;
use App\Models\Installment;
use App\Models\Layaway;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Reprogramar una cuota desde el backoffice (`REQ-LAY-011`).
 *
 * El `{installment}` se resuelve ya filtrado por el tenant: el de otro negocio
 * da `404` (SP-7). Lo que no se puede hacer —una cuota pagada, una fecha fuera
 * de la ventana— vuelve como `422` en el campo de la fecha, con el rango
 * válido escrito (SP-1).
 */
final class InstallmentController extends Controller
{
    public function reschedule(RescheduleInstallmentRequest $request, Installment $installment, RescheduleInstallmentAction $reschedule): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        try {
            $reschedule->handle($installment, $request->dueDate(), $actor, $request->reason());
        } catch (InvalidRescheduleException $exception) {
            throw ValidationException::withMessages(['due_date' => $exception->getMessage()]);
        }

        /** @var Layaway $layaway */
        $layaway = $installment->layaway()->firstOrFail();

        return to_route('layaways.show', $layaway)->with('status', sprintf('Cuota %d reprogramada.', $installment->number));
    }
}
