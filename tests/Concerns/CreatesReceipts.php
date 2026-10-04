<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Payment\ReceiptStatus;
use App\Domain\Tenancy\TenantContext;
use App\Models\Layaway;
use App\Models\Organization;
use App\Models\Receipt;

/**
 * Siembra comprobantes para los tests que necesitan que existan (`T-054`).
 *
 * Hasta `T-059` no hay `SubmitReceiptAction` —ni archivo, ni hash calculado,
 * ni comprobación de duplicados—, así que la fila sale de `ReceiptFactory`
 * (`T-055`), **dentro de la organización del apartado** aunque quien llama
 * tenga otra fijada: un comprobante de un negocio sobre el apartado de otro no
 * existe.
 */
trait CreatesReceipts
{
    /**
     * Un comprobante de ese apartado, en ese estado.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function receiptFor(Layaway $layaway, ReceiptStatus $status = ReceiptStatus::PendingReview, array $attributes = []): Receipt
    {
        $organization = Organization::query()->findOrFail($layaway->organization_id);

        return app(TenantContext::class)->runFor($organization, fn (): Receipt => Receipt::factory()->createOne([
            'layaway_id' => $layaway->getKey(),
            'status' => $status,
            ...$attributes,
        ]));
    }
}
