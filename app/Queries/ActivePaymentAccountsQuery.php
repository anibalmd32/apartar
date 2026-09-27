<?php

declare(strict_types=1);

namespace App\Queries;

use App\Domain\Payment\PaymentAccountView;
use App\Models\PaymentAccount;

/**
 * Dónde se le paga al negocio, tal como lo ve su cliente (`REQ-ORG-007`,
 * `REQ-CAT-003` RB-5, `REQ-LAY-001` RB-10).
 *
 * Las cuentas **activas**, en el orden del negocio, y solo las de la
 * organización fijada: la consulta va por el global scope. La leen tres
 * superficies —la ficha, la página de gracias y el correo de confirmación— y
 * tienen que enseñar lo mismo: lo que se copia para transferir dinero no puede
 * decir una cosa en un sitio y otra en otro. Estaba escrita dentro de
 * `ProductShowController` hasta `T-045b`, que añadió los otros dos.
 *
 * Sin ninguna, lista vacía: la pantalla manda a consultar al negocio (SP-6 de
 * `REQ-CAT-003`) y el negocio ve la alerta en su panel (`REQ-ORG-007` SP-4).
 */
final class ActivePaymentAccountsQuery
{
    /**
     * @return list<PaymentAccountView>
     */
    public function all(): array
    {
        return PaymentAccount::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentAccount $account): PaymentAccountView => PaymentAccountView::of($account))
            ->values()
            ->all();
    }
}
