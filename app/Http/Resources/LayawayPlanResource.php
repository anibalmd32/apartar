<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Layaway\LayawayTerms;
use App\Domain\Layaway\ScheduledInstallment;
use App\Domain\Layaway\ScheduleGenerator;
use App\Http\Resources\Concerns\ProjectsMoney;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El resumen del plan del formulario de apartado (`REQ-LAY-001` happy path
 * paso 2): total, inicial, cuotas y fechas estimadas, en bimoneda.
 *
 * ## Sale del apartado entero, no de la ficha multiplicada
 *
 * La ficha enseña el plan de **una** unidad porque la cantidad todavía no se
 * ha elegido. Aquí sí, y `Money::allocateEvenly()` **no es lineal**: $50
 * financiados en 3 cuotas son `16,66 · 16,66 · 16,68`, y dos unidades no son
 * el doble de eso —`33,32 · 33,32 · 33,36`— sino `33,33 · 33,33 · 33,34`. Por
 * eso el resumen se construye sobre `LayawayTerms` con la cantidad elegida, y
 * de `LayawayTerms::financingPlan()` salen el financiado y las cuotas: es la
 * misma cuenta con la que `CreateLayawayAction` congela el apartado y con la
 * que `T-046` generará el cronograma. Una división escrita aquí sería la
 * cuarta versión de la misma cifra.
 *
 * ## Las fechas son estimadas, y se dice
 *
 * El cronograma cuenta desde la **activación** (`REQ-LAY-004`), que ocurre al
 * aprobar la inicial y no se sabe cuándo será. Se estiman como si se activara
 * hoy, con `ScheduleGenerator` y en la zona del negocio, y la pantalla las
 * llama «estimadas».
 */
final class LayawayPlanResource extends JsonResource
{
    use ProjectsMoney;

    public function __construct(LayawayTerms $terms, private readonly DateTimeZone $timezone)
    {
        parent::__construct($terms);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LayawayTerms $terms */
        $terms = $this->resource;
        $plan = $terms->financingPlan();

        $schedule = (new ScheduleGenerator)->generate($terms, CarbonImmutable::now(), $this->timezone);

        return [
            'quantity' => $terms->quantity,
            'total' => $this->money($terms->total()),
            'downPayment' => $this->money($terms->totalDownPayment()),
            'financedAmount' => $this->money($terms->financedAmount()),
            'installmentsCount' => $terms->installmentsCount,
            'frequencyDays' => $terms->frequencyDays,
            'installmentAmount' => $plan === null ? null : $this->money($plan->installmentAmount()),
            'lastInstallmentAmount' => $plan !== null && $plan->hasUnevenLastInstallment() ? $this->money($plan->lastInstallmentAmount()) : null,
            'installments' => array_map(fn (ScheduledInstallment $installment): array => [
                'number' => $installment->number,
                'estimatedDueDate' => $installment->dueDate->setTimezone($this->timezone)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
                'amount' => $this->money($installment->amount),
            ], $schedule->installments),
        ];
    }
}
