import { FieldError } from '@/components/ui/field-error';
import { cn } from '@/lib/utils';
import { useEffect, useId, useRef, useState } from 'react';

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
    const [shown, setShown] = useState(value !== '' || error !== undefined);
    const [asked, setAsked] = useState(false);
    const field = useRef<HTMLTextAreaElement>(null);

    useEffect(() => {
        if (asked) {
            field.current?.focus();
        }
    }, [asked]);

    if (!shown && value === '' && error === undefined) {
        return (
            <button
                type="button"
                className="hit-area text-primary-ink self-start text-sm font-semibold hover:underline"
                onClick={() => {
                    setShown(true);
                    setAsked(true);
                }}
            >
                Agregar una nota (opcional)
            </button>
        );
    }

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                Nota (opcional)
            </label>

            <textarea
                ref={field}
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

            {error ? <FieldError id={errorId}>{error}</FieldError> : null}
        </fieldset>
    );
}
