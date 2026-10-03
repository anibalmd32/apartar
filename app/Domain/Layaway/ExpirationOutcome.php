<?php

declare(strict_types=1);

namespace App\Domain\Layaway;

/**
 * Lo que hizo una pasada de expiración (`REQ-LAY-006`).
 *
 * Dos cifras, y las dos importan: **cuántos expiraron** —stock y cupo
 * devueltos— y **cuántos se quedaron esperando** con la ventana vencida
 * porque tenían un comprobante en revisión (RB-2). La segunda es la que dice
 * que hay negocios con comprobantes sin revisar desde hace días, y un
 * contador que solo sumara los expirados la escondería.
 *
 * Es inmutable: cada apartado devuelve uno nuevo, y el job suma los de cada
 * organización con `plus()`.
 */
final readonly class ExpirationOutcome
{
    public function __construct(
        public int $expired = 0,
        public int $held = 0,
    ) {}

    public function withExpired(): self
    {
        return new self($this->expired + 1, $this->held);
    }

    public function withHeld(): self
    {
        return new self($this->expired, $this->held + 1);
    }

    public function plus(self $other): self
    {
        return new self($this->expired + $other->expired, $this->held + $other->held);
    }

    /**
     * @return array{expired: int, held: int}
     */
    public function toArray(): array
    {
        return ['expired' => $this->expired, 'held' => $this->held];
    }
}
