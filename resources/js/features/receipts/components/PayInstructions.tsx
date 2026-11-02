import { Alert } from '@/components/ui/alert';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { capitalized, suggestionWhat } from '@/features/layaway/lib/next-payment';
import { PaymentAccountChoice } from '@/features/receipts/components/ReceiptChoiceFields';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { formatAccountIdentifier } from '@/lib/identity-format';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptSuggestion } from '@/types/receipt';

interface PayInstructionsProps {
    form: ReceiptForm;
    paymentAccounts: readonly PaymentAccountView[];
    suggestion: ReceiptSuggestion | null;
    remaining: MoneyView;
    businessName: string;
}

function accountLabel(account: PaymentAccountView): string {
    return [account.methodLabel, account.bank, formatAccountIdentifier(account)].filter((part) => part !== null && part !== '').join(' · ');
}

function Lead({ suggestion, remaining }: Pick<PayInstructionsProps, 'suggestion' | 'remaining'>) {
    if (suggestion === null) {
        return (
            <p className="text-ink-2 text-[13px]">
                Te falta <MoneyLabel money={remaining} className="text-base-content font-semibold" />
            </p>
        );
    }

    return (
        <p className={suggestion.kind === 'overdue' ? 'text-error text-[13px] font-semibold' : 'text-ink-2 text-[13px]'}>
            {capitalized(suggestionWhat(suggestion))}
        </p>
    );
}

export function PayInstructions({ form, paymentAccounts, suggestion, remaining, businessName }: PayInstructionsProps) {
    const single = paymentAccounts.length === 1;

    return (
        <section className="flex flex-col gap-3" aria-labelledby="pay-heading">
            <div className="flex flex-col gap-1">
                <Lead suggestion={suggestion} remaining={remaining} />
                <h2 id="pay-heading" className="tracking-heading text-[20px] leading-tight font-extrabold lg:text-[24px]">
                    {suggestion !== null ? (
                        <>
                            Paga <MoneyLabel money={suggestion.amount} secondaryClassName="text-[15px] font-semibold" /> a:
                        </>
                    ) : (
                        'Paga a:'
                    )}
                </h2>
                {suggestion?.inReview != null ? (
                    <p className="text-ink-3 text-xs">
                        Ya descontamos los <MoneyLabel money={suggestion.inReview.amount} /> que enviaste y están en revisión.
                    </p>
                ) : null}
            </div>

            {paymentAccounts.length === 0 ? (
                <Alert tone="info">
                    {businessName} todavía no publicó sus cuentas de cobro. Pregúntale por el chat a dónde pagar y luego sube tu comprobante.
                </Alert>
            ) : null}

            {single ? <PaymentAccountList accounts={paymentAccounts} businessName={businessName} /> : null}

            {paymentAccounts.length > 1 ? (
                <>
                    <PaymentAccountChoice
                        accounts={paymentAccounts}
                        value={form.data.paymentAccount}
                        onChange={form.selectAccount}
                        label={accountLabel}
                        error={form.errors.paymentAccount}
                    />
                    {form.selectedAccount !== null ? <PaymentAccountList accounts={[form.selectedAccount]} businessName={businessName} /> : null}
                </>
            ) : null}

            {suggestion !== null && suggestion.amount.ves !== null ? (
                <p className="text-ink-3 text-xs">El monto en bolívares es a la tasa de hoy. Si pagas otro día, usa la tasa de ese día.</p>
            ) : null}
        </section>
    );
}
