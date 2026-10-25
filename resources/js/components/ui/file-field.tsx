import { cn } from '@/lib/utils';
import { cilCloudUpload } from '@coreui/icons';
import { type ChangeEvent, type InputHTMLAttributes, type ReactNode, useId, useState } from 'react';
import { Icon } from './icon';

interface FileFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'type'> {
    label: string;
    hint?: ReactNode;
    error?: string;
    preview?: ReactNode;
    buttonLabel?: string;
}

export function FileField({ label, hint, error, preview, id, className, buttonLabel = 'Elegir archivo', onChange, ...props }: FileFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;
    const [chosen, setChosen] = useState<string | null>(null);

    const describedBy = [error ? errorId : null, hint ? hintId : null].filter(Boolean).join(' ');

    const change = (event: ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(event.target.files ?? []);
        setChosen(files.length === 0 ? null : files.length === 1 ? (files[0]?.name ?? null) : `${files.length} archivos`);
        onChange?.(event);
    };

    return (
        <fieldset className="fieldset w-full">
            <span className="fieldset-legend">{label}</span>

            <div className="flex items-center gap-3">
                {preview}

                <label
                    htmlFor={fieldId}
                    className={cn(
                        'input focus-within:outline-primary min-h-11 w-full cursor-pointer gap-3 focus-within:outline-2 focus-within:outline-offset-2',
                        error && 'input-error',
                        className,
                    )}
                >
                    <span className="btn btn-sm -ml-1.5 shrink-0">
                        <Icon icon={cilCloudUpload} size={14} />
                        {buttonLabel}
                    </span>
                    <span className={cn('min-w-0 truncate text-[13px]', chosen ? 'text-base-content' : 'text-ink-3')}>
                        {chosen ?? 'Ningún archivo elegido'}
                    </span>
                    <input
                        id={fieldId}
                        type="file"
                        className="sr-only"
                        aria-label={label}
                        aria-invalid={error ? true : undefined}
                        aria-describedby={describedBy === '' ? undefined : describedBy}
                        onChange={change}
                        {...props}
                    />
                </label>
            </div>

            {hint ? (
                <p id={hintId} className="label text-xs">
                    {hint}
                </p>
            ) : null}

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
