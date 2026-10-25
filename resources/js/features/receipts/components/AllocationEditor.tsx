import { Alert } from '@/components/ui/alert';
import { SelectField } from '@/components/ui/select-field';
import { type AllocationRow, allocationProblem, centsToInput, formatCents } from '@/features/receipts/lib/allocation';
import { type ReallocationOptions } from '@/types/layaway-backoffice';
import { useForm } from '@inertiajs/react';
import { type FormEvent, useId, useState } from 'react';

interface ReallocationValues {
    [key: string]: string | { installment: number; amount: string }[];
    allocations: { installment: number; amount: string }[];
    reason: string;
}

export function AllocationEditor({ options }: { options: ReallocationOptions }) {
    const reasonId = useId();
    const [paymentId, setPaymentId] = useState(options.payments[0]?.id ?? 0);
    const payment = options.payments.find((candidate) => candidate.id === paymentId) ?? options.payments[0];
    const form = useForm<ReallocationValues>({ allocations: rowsFor(options, payment), reason: '' });

    if (payment === undefined) {
        return null;
    }

    const expected = payment.allocations.reduce((sum, allocation) => sum + allocation.cents, 0);
    const rows: AllocationRow[] = options.installments.map((installment, index) => {
        const current = payment.allocations.find((allocation) => allocation.installmentId === installment.id)?.cents ?? 0;

        return {
            installmentId: installment.id,
            number: installment.number,
            roomCents: installment.amountCents - (installment.paidCents - current),
            text: form.data.allocations[index]?.amount ?? '',
        };
    });
    const problem = allocationProblem(rows, expected);
    const blocked = form.processing || problem !== null || form.data.reason.trim().length < 10;
    const rejected = Object.entries(form.errors as Record<string, string | undefined>).find(
        ([key]) => key === 'allocations' || key.startsWith('allocations.'),
    )?.[1];

    const choose = (value: string) => {
        const next = options.payments.find((candidate) => String(candidate.id) === value);

        if (next !== undefined) {
            setPaymentId(next.id);
            form.setData('allocations', rowsFor(options, next));
            form.clearErrors();
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(payment.url, { preserveScroll: true, onSuccess: () => form.reset('reason') });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3" aria-label="Reasignar un pago">
            {options.payments.length > 1 ? (
                <SelectField
                    label="Pago"
                    name="payment"
                    options={options.payments.map((candidate) => ({ value: String(candidate.id), label: candidate.label }))}
                    value={String(payment.id)}
                    onChange={(event) => choose(event.target.value)}
                />
            ) : (
                <p className="text-sm">Pago de {payment.label}.</p>
            )}

            <table className="table-sm table" aria-label="Reparto del pago">
                <thead>
                    <tr>
                        <th scope="col">Cuota</th>
                        <th scope="col">Admite</th>
                        <th scope="col">De este pago</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, index) => (
                        <tr key={row.installmentId}>
                            <th scope="row">Cuota {row.number}</th>
                            <td>{formatCents(row.roomCents)}</td>
                            <td>
                                <input
                                    className="input input-sm w-28"
                                    inputMode="decimal"
                                    aria-label={`Importe para la cuota ${row.number}`}
                                    value={row.text}
                                    onChange={(event) =>
                                        form.setData(
                                            'allocations',
                                            form.data.allocations.map((allocation, at) =>
                                                at === index ? { ...allocation, amount: event.target.value } : allocation,
                                            ),
                                        )
                                    }
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <p className="text-sm" aria-live="polite">
                {problem ?? `El reparto suma ${formatCents(expected)}, lo mismo que el pago tiene repartido.`}
            </p>

            <div className="flex flex-col gap-1">
                <label className="fieldset-legend" htmlFor={reasonId}>
                    Motivo
                </label>
                <textarea
                    id={reasonId}
                    name="reason"
                    className="textarea w-full"
                    rows={2}
                    maxLength={500}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                    aria-invalid={form.errors.reason !== undefined}
                    aria-describedby={`${reasonId}-hint`}
                />
                <span id={`${reasonId}-hint`} className="text-ink-3 text-xs">
                    Obligatorio, de 10 a 500 caracteres: queda en la auditoría.
                </span>
                {form.errors.reason !== undefined ? <span className="text-error text-sm">{form.errors.reason}</span> : null}
            </div>

            {rejected !== undefined ? <Alert tone="error">{rejected}</Alert> : null}

            <button type="submit" className="btn btn-primary w-full" disabled={blocked}>
                Reasignar el pago
            </button>
        </form>
    );
}

function rowsFor(options: ReallocationOptions, payment: ReallocationOptions['payments'][number] | undefined): ReallocationValues['allocations'] {
    return options.installments.map((installment) => {
        const cents = payment?.allocations.find((allocation) => allocation.installmentId === installment.id)?.cents ?? 0;

        return { installment: installment.id, amount: cents > 0 ? centsToInput(cents) : '' };
    });
}
