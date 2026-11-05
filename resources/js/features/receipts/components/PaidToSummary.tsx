import { accountLabel } from '@/features/receipts/components/PayInstructions';
import { type PaymentAccountView } from '@/types/payment';

export function PaidToSummary({ account, onShow }: { account: PaymentAccountView; onShow: () => void }) {
    return (
        <div data-paid-to className="flex items-center gap-3">
            <p className="flex min-w-0 flex-1 flex-col">
                <span className="text-ink-3 text-xs">Pagaste a</span>
                <span className="line-clamp-2 text-sm font-semibold">{accountLabel(account)}</span>
            </p>
            <button type="button" className="btn btn-ghost btn-sm touch:min-h-11 shrink-0" onClick={onShow}>
                Ver los datos
            </button>
        </div>
    );
}
