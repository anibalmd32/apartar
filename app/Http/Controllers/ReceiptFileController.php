<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Receipt;
use App\Models\User;
use App\Support\SignedFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve el archivo de un comprobante (`REQ-PAY-002`).
 *
 * Los archivos viven en el disco privado `receipts`, que no tiene URL pública
 * (RB-1): esta es la única puerta, y la abren **tres** comprobaciones a la vez
 * (RB-2). Ninguna basta sola.
 *
 * ## Dos rutas, un controlador
 *
 * [05 §7.6] dibuja una sola ruta `/archivos/comprobantes/{receipt}` fuera de
 * todo grupo. Así no puede funcionar: sin tenant, el binding de `Receipt`
 * lanza `MissingTenantContextException` —un `500`, no el `404` de
 * `REQ-ORG-002` RB-6—, y una ruta no puede declarar dos superficies. Así que
 * hay una por audiencia, cada una en su grupo y con su TTL (`SignedFileUrl`):
 *
 *  - `GET /mi-cuenta/comprobantes/{receipt}/archivo` (`portal.receipts.file`),
 *    con la sesión del portal y el tenant de la ficha.
 *  - `GET /backoffice/comprobantes/{receipt}/archivo` (`receipts.file`), con
 *    el usuario del equipo y su tenant, y `during-suspension`: aprobar
 *    comprobantes es lo que `REQ-ORG-008` RB-3 conserva.
 *
 * ## En qué orden, y qué responde cada una
 *
 *  1. **Tenant** → `404`. Los resolutores corren antes que
 *     `SubstituteBindings` (`bootstrap/app.php`), así que el `{receipt}` de
 *     otra organización no existe para el binding. `signed` no está en la
 *     lista de prioridades y por eso corre **después** del binding: lo ajeno
 *     es `404` con o sin firma (`CrossTenantAccessTest` lo exige, y
 *     `ReceiptFileIsolationTest` fija el orden).
 *  2. **Firma** → `403` si falta, caducó o se tocó (SP-3). La comprueba el
 *     middleware `signed` antes de entrar aquí.
 *  3. **Policy** → `403` si es otro cliente del mismo negocio (SP-4) o un
 *     usuario sin la capacidad (`ReceiptPolicy`).
 *
 * **Consecuencia que conviene tener escrita:** una URL firmada válida abierta
 * desde **otra organización** da `404`, no el `403` que escribe SP-5. Es más
 * estricto —tampoco confirma que el comprobante exista— y es lo que manda
 * `REQ-ORG-002` RB-6 para todo recurso ajeno; lo que SP-5 protege, que la
 * firma sola no autoriza, se cumple igual.
 *
 * El `PlatformAdmin` (RB-11, SP-9) no tiene modelo ni guard todavía
 * (`T-086`): no puede autenticarse en ninguna de las dos rutas, así que queda
 * denegado por construcción hasta que llegue con su grant de soporte.
 *
 * ## Lo que se devuelve
 *
 * Un archivo que falta en disco es `404` con un `Log::error` y **sin tocar el
 * registro** (SP-6): el comprobante es evidencia, y su fila no depende de que
 * el archivo siga ahí. Si está, se sirve por `stream` con el `Content-Type`
 * que se guardó al subirlo —el MIME real, no lo que dijo el navegador—,
 * `inline` para verlo en la pestaña y `private, no-store` (RB-9): ni un proxy
 * ni el navegador deben guardar el extracto bancario de nadie. Y `nosniff`:
 * el archivo lo eligió un cliente y se abre en el origen del backoffice, así
 * que el navegador no debe adivinarle otro tipo —un HTML disfrazado de
 * imagen— que el guardado.
 */
final class ReceiptFileController extends Controller
{
    public function __invoke(Request $request, Receipt $receipt): StreamedResponse
    {
        Gate::forUser($this->actor($request))->authorize('view', $receipt);

        $disk = Storage::disk(Receipt::DISK);

        if (! $disk->exists($receipt->file_path)) {
            Log::error('receipt.file_missing', [
                'organization_id' => $receipt->organization_id,
                'receipt_id' => $receipt->getKey(),
                'file_path' => $receipt->file_path,
            ]);

            abort(404);
        }

        return $disk->response($receipt->file_path, basename($receipt->file_path), [
            'Content-Type' => $receipt->file_mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Quién pide el archivo, según la ruta por la que llega: el cliente de la
     * sesión del portal o el usuario del backoffice. Cada ruta lee **su**
     * guard; tener abierta la otra sesión en el mismo navegador no cuenta.
     */
    private function actor(Request $request): User|Customer|null
    {
        $guard = match (true) {
            $request->routeIs(SignedFileUrl::CUSTOMER_ROUTE) => 'customer',
            $request->routeIs(SignedFileUrl::BACKOFFICE_ROUTE) => 'web',
            default => throw new LogicException('ReceiptFileController solo atiende las rutas de SignedFileUrl.'),
        };

        $actor = Auth::guard($guard)->user();

        return $actor instanceof User || $actor instanceof Customer ? $actor : null;
    }
}
