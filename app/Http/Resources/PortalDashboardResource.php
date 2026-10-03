<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Account\PortalContext;
use App\Domain\Subscription\LimitEnforcer;
use App\Domain\Subscription\PlanFeature;
use App\Domain\Tenancy\OrganizationBrand;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\Concerns\ProjectsMoney;
use App\Models\Customer;
use App\Models\Layaway;
use App\Queries\BusinessBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * La portada del portal: `PortalDashboard` (`REQ-LAY-020`).
 *
 * Un bloque por negocio, en el orden que trae `CustomerBalanceQuery` —el
 * vencimiento más próximo primero (RB-4)—, cada uno con su identidad, sus
 * totales, los apartados vivos y el historial aparte (RB-5).
 *
 * **Cada bloque se compone con el tenant de su negocio fijado**: los importes
 * se proyectan con **su** fuente de tasa (RB-7) y la marca sale de **su**
 * plan. Con la sesión acotada hay un solo bloque y ya es el del tenant; con la
 * de cuenta (`T-076`) habrá varios, y este `runFor` es lo que hará que dos
 * bloques lleven tasas distintas sin mezclarlas.
 *
 * Lo que la sesión acotada **no** tiene, no viaja: `totals` es `null` —no hay
 * nada que agregar con un solo negocio (RB-3)— y no hay `feedPreview`
 * (RB-8). Las alertas son de `T-052`, los mensajes sin leer de H7 y los
 * comprobantes pendientes de H4: entran con sus tareas, en vez de viajar hoy
 * como un cero que diría algo falso.
 *
 * @property list<BusinessBalance> $resource
 */
final class PortalDashboardResource extends JsonResource
{
    use ProjectsMoney;

    /**
     * @param  list<BusinessBalance>  $blocks
     */
    public function __construct(array $blocks, private readonly PortalContext $portal)
    {
        parent::__construct($blocks);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $customer = $this->portal->scopedCustomer;

        return [
            'session' => [
                'mode' => 'scoped',
                'displayName' => $customer instanceof Customer ? $customer->full_name : '',
            ],
            'businesses' => array_map(fn (BusinessBalance $block): array => app(TenantContext::class)->runFor(
                $block->organization,
                fn (): array => $this->block($block, $request),
            ), $this->resource),
            'totals' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function block(BusinessBalance $block, Request $request): array
    {
        $summaries = fn (array $layaways): array => array_map(
            fn (Layaway $layaway): array => (new LayawaySummaryResource($layaway))->resolve($request),
            $layaways,
        );

        return [
            'business' => OrganizationBrand::of($block->organization, app(LimitEnforcer::class)->hasFeature(PlanFeature::Branding))->toArray(),
            // SP-9 · cerrado sí se dice; suspendido no (RB-12).
            'closed' => $block->businessIsClosed(),
            'totals' => [
                'activeLayaways' => count($block->active),
                'committed' => $this->money($block->committed()),
                'paid' => $this->money($block->paid()),
                'remaining' => $this->money($block->remaining()),
                'overdueInstallments' => $block->overdueInstallments(),
                'nextDueDate' => $block->nextDueDate()?->toDateString(),
                'nextDueDateLabel' => LayawaySummaryResource::dayLabel($block->nextDueDate()),
            ],
            'layaways' => $summaries($block->active),
            'history' => $summaries($block->history),
        ];
    }
}
