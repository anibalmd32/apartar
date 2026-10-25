import { cn } from '@/lib/utils';
import { useId } from 'react';

export const NOTE_MAX_LENGTH = 500;

interface ReceiptNoteFieldProps {
    value: string;
    onChange: (value: string) => void;
    error?: string;
}

export function ReceiptNoteField({ value, onChange, error }: ReceiptNoteFieldProps) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                Nota (opcional)
            </label>

            <textarea
                id={id}
                name="note"
                className={cn('textarea w-full', error && 'textarea-error')}
                rows={3}
                maxLength={NOTE_MAX_LENGTH}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${errorId} ${hintId}` : hintId}
            />

            <p id={hintId} className="label text-xs">
                Hasta {NOTE_MAX_LENGTH} caracteres. Cualquier detalle que ayude al negocio a reconocer tu pago.
            </p>

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
