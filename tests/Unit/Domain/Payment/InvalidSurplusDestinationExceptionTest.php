<?php

declare(strict_types=1);

use App\Domain\Layaway\LayawayStatus;
use App\Domain\Payment\InvalidSurplusDestinationException;

it('explica que no hay sobrante que asignar', function (): void {
    expect(InvalidSurplusDestinationException::noSurplus()->getMessage())
        ->toBe('Este pago no tiene sobrante: no hay nada que asignar.');
});

it('explica que el apartado está cerrado', function (): void {
    expect(InvalidSurplusDestinationException::layawayClosed(LayawayStatus::Cancelled)->getMessage())
        ->toContain('cancelado')
        ->and(InvalidSurplusDestinationException::layawayClosed(LayawayStatus::Expired)->getMessage())
        ->toContain('expirado');
});

it('explica que «Otro» necesita nota', function (): void {
    expect(InvalidSurplusDestinationException::missingNote()->getMessage())
        ->toContain('necesita una nota corta');
});

it('explica que la nota es larga', function (): void {
    expect(InvalidSurplusDestinationException::longNote()->getMessage())
        ->toContain('tiene como mucho');
});

it('explica que hay que elegir a una persona', function (): void {
    expect(InvalidSurplusDestinationException::unknownMember()->getMessage())
        ->toContain('Elige a qué persona del equipo');
});

it('explica que la persona no es del negocio', function (): void {
    expect(InvalidSurplusDestinationException::foreignMember()->getMessage())
        ->toContain('no es del equipo de este negocio');
});

it('explica que la persona ya no tiene acceso', function (): void {
    expect(InvalidSurplusDestinationException::inactiveMember()->getMessage())
        ->toContain('ya no tiene acceso');
});
