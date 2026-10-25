import { cn } from '@/lib/utils';
import { cilLockLocked, cilLowVision } from '@coreui/icons';
import { type InputHTMLAttributes, type ReactNode, useId, useState } from 'react';
import { Icon } from './icon';

interface IconTextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'prefix'> {
    label: string;
    error?: string;
    hint?: ReactNode;
    icon?: string[];
    revealable?: boolean;
    describedBy?: string;
}

export function IconTextField({ label, error, hint, icon, revealable = false, describedBy, id, className, type, ...props }: IconTextFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const errorId = `${fieldId}-error`;
    const hintId = `${fieldId}-hint`;
    const [visible, setVisible] = useState(false);

    const described = [error ? errorId : null, hint ? hintId : null, describedBy ?? null].filter(Boolean).join(' ');
    const locked = props.disabled === true && !revealable;

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={fieldId}>
                {label}
            </label>

            <label className={cn('input w-full gap-2 px-3', error && 'input-error', className)}>
                {icon ? <Icon icon={icon} className="text-ink-3" /> : null}

                <input
                    id={fieldId}
                    className="grow"
                    type={revealable && visible ? 'text' : type}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={described === '' ? undefined : described}
                    {...props}
                />

                {revealable ? (
                    <button
                        type="button"
                        className="hit-area text-ink-3 hover:text-base-content -mr-1 cursor-pointer rounded p-1"
                        onClick={() => setVisible((current) => !current)}
                        aria-pressed={visible}
                        aria-label={visible ? 'Ocultar lo escrito' : 'Mostrar lo escrito'}
                    >
                        <Icon icon={cilLowVision} />
                    </button>
                ) : null}

                {locked ? <Icon icon={cilLockLocked} className="text-ink-3" /> : null}
            </label>

            {hint ? (
                <p id={hintId} className="label text-xs whitespace-normal">
                    {hint}
                </p>
            ) : null}

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs whitespace-normal">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
