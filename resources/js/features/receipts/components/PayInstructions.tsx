import { Alert } from '@/components/ui/alert';
import { Icon } from '@/components/ui/icon';
import { PaymentAccountList } from '@/features/catalog/components/PaymentAccountList';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { suggestionWhat } from '@/features/layaway/lib/next-payment';
import { PaymentAccountChoice } from '@/features/receipts/components/ReceiptChoiceFields';
import { type ReceiptForm } from '@/features/receipts/hooks/useReceiptForm';
import { formatAccountIdentifier } from '@/lib/identity-format';
import { type PaymentAccountView } from '@/types/payment';
import { type NextPaymentInReview } from '@/types/portal';
import { type ReceiptSuggestion } from '@/types/receipt';
import { cilClock } from '@coreui/icons';

interface PayInstructionsProps {
    form: ReceiptForm;
    paymentAccounts: readonly PaymentAccountView[];
    suggestion: ReceiptSuggestion | null;
    coveredByReview?: NextPaymentInReview | null;
    businessName: string;
}

const HEADING = 'text-[20px] leading-tight font-extrabold lg:text-[24px] lg:tracking-heading';

export function accountLabel(account: PaymentAccountView): string {
    return [account.methodLabel, account.bank, formatAccountIdentifier(account)].filter((part) => part !== null && part !== '').join(' · ');
}

function SuggestedHeading({ suggestion }: { suggestion: ReceiptSuggestion }) {
    const overdue = suggestion.kind === 'overdue';

    return (
        <>
            <p className={overdue ? 'text-error text-[13px] font-semibold' : 'text-ink-2 text-[13px]'}>
                {overdue ? 'Ponte al día con' : 'Te toca pagar'} {suggestionWhat(suggestion)}.
            </p>
            <h2 id="pay-heading" className={HEADING}>
                Paga <MoneyLabel money={suggestion.amount} secondaryClassName="text-[15px] font-semibold" /> a:
            </h2>
            {suggestion.inReview !== null ? (
                <p className="text-ink-3 text-xs">
                    Ya descontamos los <MoneyLabel money={suggestion.inReview.amount} /> que enviaste y están en revisión.
                </p>
            ) : null}
        </>
    );
}

function CoveredHeading({ inReview, hasAccounts }: { inReview: NextPaymentInReview; hasAccounts: boolean }) {
    const many = inReview.count > 1;

    return (
        <>
            <h2 id="pay-heading" className="flex items-center gap-2 text-[17px] font-bold">
                <Icon icon={cilClock} size={18} className="text-info shrink-0" />
                Tu pago está en revisión
            </h2>
            <p className="text-ink-2 text-sm">
                Ya enviaste <MoneyLabel money={inReview.amount} className="text-base-content font-semibold" />
                {inReview.submittedOn !== null ? ` el ${inReview.submittedOn}` : ''}
                {many ? ` en ${inReview.count} comprobantes` : ''} y cubre lo que te toca ahora. Cuando lo aprueben, verás aquí lo siguiente que debes
                pagar.
            </p>
            {hasAccounts ? <p className="text-ink-2 text-sm">Si quieres adelantar otro pago, estas son las cuentas:</p> : null}
        </>
    );
}

export function PayInstructions({ form, paymentAccounts, suggestion, coveredByReview = null, businessName }: PayInstructionsProps) {
    const single = paymentAccounts.length === 1;

    return (
        <section className="flex flex-col gap-3" aria-labelledby="pay-heading">
            <div className="flex flex-col gap-1">
                {suggestion !== null ? <SuggestedHeading suggestion={suggestion} /> : null}
                {suggestion === null && coveredByReview !== null ? (
                    <CoveredHeading inReview={coveredByReview} hasAccounts={paymentAccounts.length > 0} />
                ) : null}
                {suggestion === null && coveredByReview === null ? (
                    <h2 id="pay-heading" className={HEADING}>
                        Cuentas para pagar
                    </h2>
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
