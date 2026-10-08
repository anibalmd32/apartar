<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Receipt;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Entró un comprobante (`REQ-PAY-004` RB-2, `REQ-PAY-001` RB-8).
 *
 * Lo emite `SubmitReceiptAction` **después del commit**, y se difunde por el
 * canal privado de la organización (`organization.{orgId}.receipts`, [05
 * §7.7]) para que la bandeja abierta sume uno al badge y avise.
 *
 * ## El payload es lo mínimo, y no lleva a nadie
 *
 * `id`, `layawayCode`, `declaredAmount`, `declaredCurrency` y `submittedAt`.
 * **Ni el nombre del cliente, ni su documento, ni el banco, ni la referencia,
 * ni la nota** (RB-2): un mensaje difundido pasa por Reverb y por cada
 * navegador suscrito, y lo que no viaja no se puede filtrar. Quien quiera el
 * detalle lo pide a la bandeja, que lo sirve con la sesión y el tenant puestos.
 *
 * `declaredCurrency` no está en la lista de RB-2, y va: «850,00» sin moneda no
 * se puede leer —son dólares o bolívares, y la diferencia es de cuarenta
 * veces—. Una moneda no es un dato personal. Anotado en [22].
 *
 * El evento se construye **con escalares**, no con el modelo: la difusión va
 * por la cola, y un `Receipt` serializado se volvería a leer en el worker sin
 * organización fijada. Con escalares no hay nada que leer.
 *
 * ## Un fallo aquí no deshace nada
 *
 * SP-6: el comprobante ya está confirmado cuando esto se emite, y quien lo
 * emite se traga el fallo (lo informa y sigue). La bandeja lo enseña en la
 * próxima carga, y el contador lo recoge en su siguiente consulta.
 */
final class ReceiptSubmitted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * La difusión va por `high` ([90], `ASU-INF-02`): es un aviso que alguien
     * está esperando con la bandeja abierta, y detrás de una exportación
     * llegaría cuando ya no sirve.
     */
    public string $broadcastQueue = 'high';

    public function __construct(
        public readonly int $organizationId,
        public readonly int $receiptId,
        public readonly string $layawayCode,
        public readonly string $declaredAmount,
        public readonly string $declaredCurrency,
        public readonly string $submittedAt,
    ) {}

    /**
     * El evento de un comprobante recién confirmado.
     *
     * El apartado tiene que venir cargado: es de donde sale el código, y
     * leerlo aquí sería una consulta más fuera de la transacción.
     */
    public static function of(Receipt $receipt, string $layawayCode): self
    {
        return new self(
            organizationId: (int) $receipt->organization_id,
            receiptId: (int) $receipt->getKey(),
            layawayCode: $layawayCode,
            declaredAmount: (string) $receipt->declared_amount,
            declaredCurrency: $receipt->declared_currency->value,
            submittedAt: ($receipt->created_at ?? now())->toIso8601String(),
        );
    }

    /**
     * El canal de la bandeja de **esa** organización (RB-1).
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelFor($this->organizationId))];
    }

    /**
     * El nombre del canal, sin el prefijo `private-` que pone el broadcaster.
     */
    public static function channelFor(int $organizationId): string
    {
        return sprintf('organization.%d.receipts', $organizationId);
    }

    /**
     * Lo que viaja (RB-2). Si se añade un campo, `ReceiptSubmittedTest` lo
     * rechaza hasta que alguien decida que no es un dato personal.
     *
     * @return array{id: int, layawayCode: string, declaredAmount: string, declaredCurrency: string, submittedAt: string}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->receiptId,
            'layawayCode' => $this->layawayCode,
            'declaredAmount' => $this->declaredAmount,
            'declaredCurrency' => $this->declaredCurrency,
            'submittedAt' => $this->submittedAt,
        ];
    }
}
