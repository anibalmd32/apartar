import { type ReadingState } from '@/features/receipts/hooks/useReceiptReading';
import { useId } from 'react';

export function ReadingStatus({ state }: { state: ReadingState }) {
    if (state === 'reading') {
        return (
            <p role="status" className="bg-surface-2 text-ink-2 flex items-center gap-2 rounded-xl p-3 text-sm">
                <span className="loading loading-spinner loading-sm" aria-hidden="true" />
                Estamos validando el comprobante…
            </p>
        );
    }

    return state === 'read' ? (
        <p role="status" className="text-ink-2 text-sm">
            Revisa los datos del pago y completa lo que esté vacío.
        </p>
    ) : null;
}

export function PaymentNoteField({ value, error, onChange }: { value: string; error?: string; onChange: (value: string) => void }) {
    const noteId = useId();

    return (
        <div className="flex flex-col gap-1">
            <label className="fieldset-legend" htmlFor={noteId}>
                Nota
            </label>
            <textarea
                id={noteId}
                name="note"
                className="textarea w-full"
                rows={2}
                maxLength={500}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error !== undefined}
                aria-describedby={`${noteId}-hint`}
            />
            <span id={`${noteId}-hint`} className="text-ink-3 text-xs">
                Obligatoria: quién lo recibió, dónde y cómo.
            </span>
            {error !== undefined ? <span className="text-error text-sm">{error}</span> : null}
        </div>
    );
}
