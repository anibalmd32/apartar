import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { cn } from '@/lib/utils';
import { type NextPaymentSuggestion } from '@/types/portal';
import { cilCloudUpload } from '@coreui/icons';
import { Link } from '@inertiajs/react';

interface PortalNextPaymentProps {
    suggestion: NextPaymentSuggestion;
    receiptUrl: string | null;
    className?: string;
}

export function PortalNextPayment({ suggestion, receiptUrl, className }: PortalNextPaymentProps) {
    return (
        <section
            aria-label="Próximo pago"
            className={cn('bg-base-100 border-primary/50 shadow-card flex flex-col gap-3.5 rounded-2xl border-[1.5px] p-5', className)}
        >
            <h2 className="text-eyebrow text-ink-3">Te sugerimos pagar</h2>
            <p className="flex flex-col gap-1">
                <span className="text-ink-2 text-[13px] font-semibold">
                    {suggestion.kind === 'down_payment'
                        ? suggestion.started
                            ? 'Lo que falta de la inicial: '
                            : 'La inicial: '
                        : `La cuota ${suggestion.installmentNumber}: `}
                </span>
                <MoneyLabel money={suggestion.amount} className="text-[30px] leading-tight font-extrabold tracking-[-0.6px]" stacked />
            </p>

            {receiptUrl !== null ? (
                <Link href={receiptUrl} className="btn btn-primary btn-lg btn-block">
                    <Icon icon={cilCloudUpload} size={18} />
                    Subir comprobante
                </Link>
            ) : null}

            <p className="text-ink-2 text-center text-[13px]">
                Si prefieres pagarlo todo de una vez: <MoneyLabel money={suggestion.payOff} className="text-primary-ink font-semibold" />
            </p>
            <p className="text-ink-3 text-center text-xs">
                Sin descuento por adelantar.
                {suggestion.payOff.ves !== null ? ' El monto en bolívares es a la tasa de hoy y puede variar el día que pagues.' : null}
            </p>
        </section>
    );
}
