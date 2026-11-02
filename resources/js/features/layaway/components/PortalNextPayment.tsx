import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { suggestionWhat } from '@/features/layaway/lib/next-payment';
import { cn } from '@/lib/utils';
import { type NextPaymentInReview, type NextPaymentSuggestion } from '@/types/portal';
import { cilClock, cilCloudUpload } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface PortalNextPaymentProps {
    suggestion: NextPaymentSuggestion;
    receiptUrl: string | null;
    className?: string;
}

function InReviewNote({ inReview }: { inReview: NextPaymentInReview }) {
    const many = inReview.count > 1;

    return (
        <p className="bg-info-soft text-base-content flex items-start gap-2.5 rounded-xl px-3.5 py-3 text-sm">
            <Icon icon={cilClock} size={16} className="text-info mt-0.5 shrink-0" />
            <span>
                Ya enviaste <MoneyLabel money={inReview.amount} className="font-semibold" />
                {inReview.submittedOn !== null ? ` el ${inReview.submittedOn}` : ''}
                {many ? ` en ${inReview.count} comprobantes` : ''}; {many ? 'los' : 'lo'} están revisando.
            </span>
        </p>
    );
}

function Suggested({ suggestion }: { suggestion: NextPaymentSuggestion }) {
    const overdue = suggestion.kind === 'overdue';

    return (
        <>
            <h2 className={cn('text-sm font-semibold', overdue ? 'text-error' : 'text-ink-2')}>
                {overdue ? 'Ponte al día con' : 'Te sugerimos pagar'} {suggestionWhat(suggestion)}
            </h2>
            <MoneyLabel money={suggestion.amount} className="tracking-heading text-[30px] leading-tight font-extrabold" stacked />
        </>
    );
}

function Covered() {
    return (
        <div className="flex flex-col gap-1">
            <h2 className="text-[17px] font-bold">Tu pago está en revisión</h2>
            <p className="text-ink-2 text-sm">Cuando lo aprueben, verás aquí lo que te toque pagar después.</p>
        </div>
    );
}

export function PortalNextPayment({ suggestion, receiptUrl, className }: PortalNextPaymentProps) {
    const { covered, inReview, alternative, payOff } = suggestion;

    return (
        <section
            aria-label="Próximo pago"
            className={cn(
                'bg-base-100 shadow-card flex flex-col gap-3.5 rounded-2xl border-[1.5px] p-5',
                covered ? 'border-line' : suggestion.kind === 'overdue' ? 'border-error/50' : 'border-primary/50',
                className,
            )}
        >
            {covered ? <Covered /> : <Suggested suggestion={suggestion} />}

            {inReview !== null ? <InReviewNote inReview={inReview} /> : null}

            {receiptUrl !== null ? (
                <Link href={receiptUrl} className={cn('btn btn-block', covered ? 'btn-secondary' : 'btn-primary btn-lg')}>
                    <Icon icon={cilCloudUpload} size={18} />
                    {covered ? 'Subir otro comprobante' : 'Pagar'}
                </Link>
            ) : null}

            {!covered && alternative !== null ? (
                <p className="text-ink-2 text-[13px]">
                    Si hoy no te alcanza, paga al menos la cuota {alternative.installmentNumber}:{' '}
                    <MoneyLabel money={alternative.amount} className="text-base-content font-semibold" />.
                </p>
            ) : null}

            {Number(payOff.cents) > 0 ? (
                <div className="border-line flex flex-col gap-1 border-t pt-3">
                    <p className="text-ink-2 text-[13px]">
                        Si prefieres pagarlo todo de una vez: <MoneyLabel money={payOff} className="text-primary-ink font-semibold" />
                    </p>
                    <p className="text-ink-3 text-xs">
                        Sin descuento por adelantar.
                        {payOff.ves !== null ? ' El monto en bolívares es a la tasa de hoy y puede variar el día que pagues.' : null}
                    </p>
                </div>
            ) : null}
        </section>
    );
}
