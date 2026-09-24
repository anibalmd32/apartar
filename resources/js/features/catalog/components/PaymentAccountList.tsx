import { type PaymentAccountView } from '@/types/payment';

/**
 * Dónde se le paga al negocio, **antes** de comprometerse (`REQ-CAT-003`
 * RB-5, SP-6; `REQ-ORG-007`).
 *
 * Es información nueva del pivote y es imprescindible: quien va a apartar
 * tiene que saber si puede pagar por Pago móvil o solo en efectivo antes de
 * pulsar nada. Cada cuenta se pinta con lo que el servidor manda resuelto
 * —`methodLabel`, `identifierLabel`—, que es la misma forma que ve el dueño en
 * su backoffice: lo que se copia para transferir dinero no puede decir una
 * cosa aquí y otra allí.
 *
 * Llegan **solo las activas**. Sin ninguna, SP-6: se manda a consultar al
 * negocio. El enlace al chat es de `T-080`; hasta entonces, el texto sin un
 * botón que no llevaría a ninguna parte.
 */

interface PaymentAccountListProps {
    accounts: readonly PaymentAccountView[];
    businessName: string;
}

export function PaymentAccountList({ accounts, businessName }: PaymentAccountListProps) {
    if (accounts.length === 0) {
        return <p className="text-sm opacity-80">Consulta a {businessName} por el chat cómo pagarle: todavía no ha publicado sus formas de pago.</p>;
    }

    return (
        <ul className="flex flex-col gap-3">
            {accounts.map((account) => (
                <li key={account.id} className="bg-base-200 rounded-box p-3 text-sm">
                    <p className="font-medium">
                        {account.methodLabel}
                        {account.bank !== null ? ` · ${account.bank}` : null}
                    </p>
                    <p>
                        <span className="opacity-70">{account.identifierLabel}:</span>{' '}
                        <span className="font-mono select-all">{account.identifier}</span>
                    </p>
                    <p>
                        {account.holderName} · {account.holderDocument}
                    </p>
                    {account.instructions !== null ? <p className="mt-1 opacity-70">{account.instructions}</p> : null}
                </li>
            ))}
        </ul>
    );
}
