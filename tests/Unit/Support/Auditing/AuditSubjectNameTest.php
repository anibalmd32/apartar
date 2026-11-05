<?php

declare(strict_types=1);

use App\Models\Receipt;
use App\Support\Auditing\AuditSubjectName;

it('las entradas viejas de la auditoría se leen en español, con el nombre del negocio y no el de la clase', function (string $stored, string $shown): void {
    expect(AuditSubjectName::humanize($stored))->toBe($shown);
})->with([
    ['Comprobante aprobado: Receipt #1', 'Comprobante aprobado: comprobante n.º 1'],
    ['Pago registrado a mano: Payment #14', 'Pago registrado a mano: pago n.º 14'],
    ['Factura emitida: SubscriptionInvoice #3', 'Factura emitida: factura n.º 3'],
    ['Acceso concedido: SupportAccessGrant #4', 'Acceso concedido: acceso de soporte n.º 4'],
]);

it('lo que no es un nombre de clase conocido se deja tal cual', function (): void {
    expect(AuditSubjectName::humanize('Ajustes actualizados #2'))->toBe('Ajustes actualizados #2')
        ->and(AuditSubjectName::humanize('Algo raro: Widget #9'))->toBe('Algo raro: Widget #9')
        ->and(AuditSubjectName::humanize(null))->toBeNull();
});

it('las entradas nuevas ya se escriben en español', function (): void {
    expect(AuditSubjectName::of(new Receipt, 7))->toBe('comprobante n.º 7');
});
