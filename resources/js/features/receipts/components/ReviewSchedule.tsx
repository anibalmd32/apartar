import { Badge, type BadgeTone } from '@/components/ui/badge';
import { CompactRow } from '@/components/ui/compact-list';
import { SectionCard } from '@/components/ui/section-card';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type ReceiptReviewProps } from '@/types/receipt';

const TONES: Readonly<Record<string, BadgeTone>> = {
    pending: 'neutral',
    partially_paid: 'warning',
    paid: 'success',
    overdue: 'error',
};

export function ReviewSchedule({ layaway, installments }: Pick<ReceiptReviewProps, 'layaway' | 'installments'>) {
    return (
        <SectionCard title="Cronograma" flush>
            {installments.length === 0 ? (
                <p className="text-ink-2 px-5 pb-5 text-sm">
                    Espera la inicial de <MoneyLabel money={layaway.downPayment} />
                    {layaway.downPaymentProgress !== null ? (
                        <>
                            {' '}
                            (abonado <MoneyLabel money={layaway.downPaymentProgress.paid} />, faltan{' '}
                            <MoneyLabel money={layaway.downPaymentProgress.missing} />)
                        </>
                    ) : null}
                    : el cronograma nace al completarla.
                </p>
            ) : (
                <>
                    <ul className="divide-line border-line flex flex-col divide-y border-t md:hidden" aria-label="Cuotas">
                        {installments.map((installment) => (
                            <CompactRow
                                key={installment.number}
                                className="px-5"
                                title={`Cuota ${installment.number}`}
                                amount={<MoneyLabel money={installment.amount} compact />}
                                status={<Badge tone={TONES[installment.status] ?? 'neutral'}>{installment.statusLabel}</Badge>}
                                meta={
                                    <>
                                        <span>Vence el {installment.dueDateLabel}</span>
                                        {Number(installment.paid.cents) > 0 && installment.status !== 'paid' ? (
                                            <span>
                                                Abonado <MoneyLabel money={installment.paid} compact />
                                            </span>
                                        ) : null}
                                    </>
                                }
                            />
                        ))}
                    </ul>
                    <div className="border-line hidden overflow-x-auto border-t md:block">
                        <table className="table">
                            <caption className="sr-only">Cronograma</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Cuota</th>
                                    <th scope="col">Vence</th>
                                    <th scope="col">Pagado</th>
                                    <th scope="col">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                {installments.map((installment) => (
                                    <tr key={installment.number}>
                                        <td className="font-semibold">{installment.number}</td>
                                        <td>{installment.dueDateLabel}</td>
                                        <td>
                                            <MoneyLabel money={installment.paid} /> de <MoneyLabel money={installment.amount} />
                                        </td>
                                        <td>{installment.statusLabel}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </SectionCard>
    );
}
