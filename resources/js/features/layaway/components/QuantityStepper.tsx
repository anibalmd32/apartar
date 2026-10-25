import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilMinus, cilPlus } from '@coreui/icons';
import { useId } from 'react';

interface QuantityStepperProps {
    value: number;
    max: number;
    onChange: (quantity: number) => void;
    hint: string;
    error?: string;
}

export function QuantityStepper({ value, max, onChange, hint, error }: QuantityStepperProps) {
    const id = useId();
    const describedBy = [error ? `${id}-error` : null, `${id}-hint`].filter(Boolean).join(' ');
    const step = 'flex h-full w-11 cursor-pointer items-center justify-center disabled:cursor-not-allowed disabled:opacity-40';

    return (
        <fieldset className="fieldset w-full">
            <label className="fieldset-legend" htmlFor={id}>
                Cantidad
            </label>

            <div
                className={cn(
                    'border-line-strong bg-base-100 has-[input:focus-visible]:outline-primary flex h-12 w-fit items-center rounded-xl border outline-offset-2 has-[input:focus-visible]:outline-2',
                    error && 'border-error',
                )}
            >
                <button type="button" className={step} onClick={() => onChange(value - 1)} disabled={value <= 1} aria-label="Quitar una unidad">
                    <Icon icon={cilMinus} />
                </button>
                <input
                    id={id}
                    name="quantity"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={max}
                    value={value}
                    onChange={(event) => onChange(Number(event.target.value))}
                    className="w-11 [appearance:textfield] bg-transparent text-center text-base font-bold outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    required
                />
                <button type="button" className={step} onClick={() => onChange(value + 1)} disabled={value >= max} aria-label="Añadir una unidad">
                    <Icon icon={cilPlus} />
                </button>
            </div>

            <p id={`${id}-hint`} className="label text-xs">
                {hint}
            </p>
            {error ? (
                <p id={`${id}-error`} role="alert" className="label text-error text-xs">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
