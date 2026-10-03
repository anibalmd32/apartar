<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domain\Money\MoneyFormatter;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Layaway;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * La portada del portal con la sesión acotada (`REQ-IDN-008` RB-4,
 * `REQ-LAY-020`).
 *
 * **Es la versión mínima de `T-049`**: lo que hace falta para afirmar que
 * documento + código dan acceso a **todos** los apartados de la ficha en ese
 * negocio, y no solo al del código usado. La portada de verdad —saldos en las
 * dos monedas, próximo vencimiento, alertas, agrupación por negocio con la
 * sesión de cuenta— es de **`T-051`** (`CustomerBalanceQuery`,
 * `PortalContext`), que sustituye el cuerpo de este controlador.
 *
 * Todo va por el tenant que fijó `ResolvePortalTenant`, el de la ficha: los
 * apartados de otro negocio no existen para esta consulta. Y las props no
 * llevan ningún `id` (`REQ-IDN-004` RB-4): el apartado se nombra por su código
 * y la persona por su documento formateado.
 */
final class DashboardController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(): Response
    {
        $customer = Auth::guard('customer')->user();

        if (! $customer instanceof Customer) {
            throw new LogicException('La portada del portal se sirve detrás de EnsurePortalSession.');
        }

        $organization = $this->context->organization();

        $layaways = Layaway::query()
            ->where('customer_id', $customer->getKey())
            ->orderByDesc('id')
            ->get()
            ->map(fn (Layaway $layaway): array => [
                'code' => $layaway->code,
                'productName' => $layaway->product_name_snapshot,
                'quantity' => $layaway->quantity,
                'status' => $layaway->status->value,
                'statusLabel' => $layaway->status->customerLabel(),
                'total' => MoneyFormatter::format($layaway->total_cents),
            ])
            ->values()
            ->all();

        return Inertia::render('portal/Dashboard', [
            'organization' => ['name' => $organization->name],
            'customer' => [
                'fullName' => $customer->full_name,
                'documentNumber' => DocumentNumber::tryFromInput($customer->document_number)?->formatted() ?? $customer->document_number,
            ],
            'layaways' => $layaways,
        ]);
    }
}
