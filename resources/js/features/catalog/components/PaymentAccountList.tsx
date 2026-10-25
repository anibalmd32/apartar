import { type PaymentAccountView } from '@/types/payment';

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
