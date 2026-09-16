<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\PaymentAccount;

/**
 * Una cuenta de cobro tal como se pinta (`REQ-ORG-007`, contrato del módulo
 * 04).
 *
 * Es el tipo que viaja a las page props, y lleva **dos campos que no están en
 * la fila**: `methodLabel` e `identifierLabel`. Los dos salen de
 * `PaymentMethod` y viajan resueltos por el mismo motivo por el que
 * `SettingDefinition` lleva su `scopeNote` desde `T-014`: la alternativa es un
 * diccionario de seis métodos escrito en el `.tsx`, es decir, un segundo censo
 * de los casos del enum que se queda viejo el día que llegue el séptimo — y se
 * queda viejo **en la pantalla**, que es donde alguien copia un número para
 * transferir dinero.
 *
 * La misma forma viaja al backoffice y —cuando llegue `REQ-CAT-003`— al
 * cliente que va a pagar. Que sea una sola es lo que garantiza que el dueño vea
 * exactamente lo que ve su cliente: una cuenta que se pinta distinta en cada
 * sitio es una cuenta que alguien va a copiar mal.
 *
 * Espejo exacto de `PaymentAccountView` en `resources/js/types/payment.ts`.
 */
final readonly class PaymentAccountView
{
    private function __construct(
        public int $id,
        public PaymentMethod $method,
        /** Nombre del método para una persona: «Pago móvil», no `pago_movil`. */
        public string $methodLabel,
        /** Qué contiene `identifier` en este método (RB-2). */
        public string $identifierLabel,
        public ?string $bank,
        public string $holderName,
        public string $holderDocument,
        public string $identifier,
        public ?string $instructions,
        public bool $isActive,
        public int $position,
    ) {}

    public static function of(PaymentAccount $account): self
    {
        return new self(
            id: (int) $account->getKey(),
            method: $account->method,
            methodLabel: $account->method->label(),
            identifierLabel: $account->method->identifierLabel(),
            bank: $account->bank,
            holderName: $account->holder_name,
            holderDocument: $account->holder_document,
            identifier: $account->identifier,
            instructions: $account->instructions,
            isActive: $account->is_active,
            position: $account->position,
        );
    }

    /**
     * Forma con la que viaja a las page props.
     *
     * **Las desactivadas viajan igual**, marcadas con `isActive`. Esconderlas
     * convertiría la degradación del borrado (RB-6) en un borrado a efectos
     * prácticos: el dueño vería desaparecer una cuenta que sigue ahí sosteniendo
     * sus comprobantes, y no tendría forma de volver a activarla.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method->value,
            'methodLabel' => $this->methodLabel,
            'identifierLabel' => $this->identifierLabel,
            'bank' => $this->bank,
            'holderName' => $this->holderName,
            'holderDocument' => $this->holderDocument,
            'identifier' => $this->identifier,
            'instructions' => $this->instructions,
            'isActive' => $this->isActive,
            'position' => $this->position,
        ];
    }
}
