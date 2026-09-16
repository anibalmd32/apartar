import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { type PaymentAccountView } from '@/types/payment';
import { router } from '@inertiajs/react';

/**
 * Una cuenta de cobro en la lista, con lo que se puede hacer sobre ella
 * (`REQ-ORG-007`).
 *
 * **Las desactivadas siguen aquí, marcadas.** Esconderlas convertiría la
 * degradación del borrado (RB-6) en un borrado a efectos prácticos: el dueño
 * vería desaparecer una cuenta que sigue sosteniendo sus comprobantes y no
 * tendría cómo volver a activarla.
 *
 * El botón de eliminar **no promete que vaya a eliminar**: si la cuenta tiene
 * comprobantes, el servidor la desactiva y lo dice (SP-3). Aquí no se adivina
 * cuál de las dos cosas va a pasar —quién tiene comprobantes lo sabe el
 * servidor— y por eso el aviso llega después y no antes.
 */

interface PaymentAccountRowProps {
    account: PaymentAccountView;
    /** El plan no admite otra cuenta activa: reactivar quedaría bloqueado. */
    atLimit: boolean;
    onEdit: () => void;
}

export function PaymentAccountRow({ account, atLimit, onEdit }: PaymentAccountRowProps) {
    const url = `/backoffice/cuentas-de-cobro/${account.id}`;

    /**
     * Encender y apagar la cuenta reenvía la ficha entera con `is_active`
     * cambiado, y no un endpoint aparte: las rutas de `REQ-ORG-007` son cuatro
     * y el estado es un campo más de la cuenta, no una operación distinta.
     */
    const toggle = () =>
        router.put(
            url,
            {
                method: account.method,
                bank: account.bank ?? '',
                holder_name: account.holderName,
                holder_document: account.holderDocument,
                identifier: account.identifier,
                instructions: account.instructions ?? '',
                is_active: !account.isActive,
            },
            { preserveScroll: true },
        );

    const remove = () => router.delete(url, { preserveScroll: true });

    const cannotActivate = !account.isActive && atLimit;

    return (
        <tr className={account.isActive ? undefined : 'opacity-60'}>
            <td>
                <div className="flex flex-col">
                    <span className="font-medium">{account.methodLabel}</span>
                    {account.bank ? <span className="text-xs opacity-70">{account.bank}</span> : null}
                </div>
            </td>

            <td>
                <div className="flex flex-col">
                    <span className="font-mono text-sm">{account.identifier}</span>
                    <span className="text-xs opacity-70">{account.identifierLabel}</span>
                </div>
            </td>

            <td>
                <div className="flex flex-col">
                    <span>{account.holderName}</span>
                    <span className="text-xs opacity-70">{account.holderDocument}</span>
                </div>
            </td>

            <td>
                {/* El color nunca es lo único que distingue un estado. */}
                <Badge tone={account.isActive ? 'success' : 'neutral'}>{account.isActive ? 'Activa' : 'Desactivada'}</Badge>
            </td>

            <td className="text-right">
                <div className="flex flex-wrap justify-end gap-1">
                    <Button size="sm" variant="ghost" onClick={onEdit}>
                        Editar
                    </Button>

                    <Button
                        size="sm"
                        variant="ghost"
                        onClick={toggle}
                        disabled={cannotActivate}
                        title={cannotActivate ? 'Tu plan no admite otra cuenta activa.' : undefined}
                    >
                        {account.isActive ? 'Desactivar' : 'Activar'}
                    </Button>

                    <Button size="sm" variant="ghost" onClick={remove}>
                        Eliminar
                    </Button>
                </div>
            </td>
        </tr>
    );
}
