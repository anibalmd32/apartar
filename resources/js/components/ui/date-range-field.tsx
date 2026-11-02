import { cn } from '@/lib/utils';
import { cilCalendar } from '@coreui/icons';
import { DateInput } from './date-input';
import { Icon } from './icon';

export interface DateValueChange {
    readonly target: { readonly value: string };
}

interface DateRangeFieldProps {
    label: string;
    fromLabel: string;
    toLabel?: string;
    from: string;
    to: string;
    onFromChange: (event: DateValueChange) => void;
    onToChange: (event: DateValueChange) => void;
    fromName?: string;
    toName?: string;
    showLabel?: boolean;
    className?: string;
}

function Leading({ label }: { label: string }) {
    return (
        <>
            <Icon icon={cilCalendar} size={16} className="text-ink-3 shrink-0" />
            <span className="text-ink-3 shrink-0 text-[13px]">{label}</span>
        </>
    );
}

export function DateRangeField({
    label,
    fromLabel,
    toLabel = 'Hasta',
    from,
    to,
    onFromChange,
    onToChange,
    fromName = 'from',
    toName = 'to',
    showLabel = false,
    className,
}: DateRangeFieldProps) {
    const box = (filled: boolean) => cn('gap-2', filled && 'border-primary');

    return (
        <div role="group" aria-label={label} className={cn('flex w-full flex-col gap-2 sm:w-auto', className)}>
            {showLabel ? (
                <span aria-hidden="true" className="fieldset-legend py-0">
                    {label}
                </span>
            ) : null}
            <div className="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:items-center">
                <div className="min-w-0 sm:w-auto">
                    <DateInput
                        name={fromName}
                        ariaLabel={fromLabel}
                        value={from}
                        max={to || null}
                        leading={<Leading label={fromLabel} />}
                        className={box(from !== '')}
                        onChange={(value) => onFromChange({ target: { value } })}
                    />
                </div>
                <div className="min-w-0 sm:w-auto">
                    <DateInput
                        name={toName}
                        ariaLabel={toLabel}
                        value={to}
                        min={from || null}
                        leading={<Leading label={toLabel} />}
                        className={box(to !== '')}
                        onChange={(value) => onToChange({ target: { value } })}
                    />
                </div>
            </div>
        </div>
    );
}
