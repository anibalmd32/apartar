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
    className?: string;
}

export function DateRangeField({
    label,
    fromLabel,
    toLabel = 'hasta',
    from,
    to,
    onFromChange,
    onToChange,
    fromName = 'from',
    toName = 'to',
    className,
}: DateRangeFieldProps) {
    const active = from !== '' || to !== '';
    const box = cn('input w-full min-w-0 sm:w-auto', active && 'border-primary bg-primary-soft');

    return (
        <div role="group" aria-label={label} className={cn('grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:items-center', className)}>
            <div className={box}>
                <Icon icon={cilCalendar} className="text-ink-3" />
                <span className="text-ink-3 shrink-0 text-[13px]">{fromLabel}</span>
                <DateInput
                    bare
                    name={fromName}
                    ariaLabel={fromLabel}
                    value={from}
                    max={to || null}
                    onChange={(value) => onFromChange({ target: { value } })}
                />
            </div>
            <div className={box}>
                <span className="text-ink-3 shrink-0 text-[13px]">{toLabel}</span>
                <DateInput
                    bare
                    name={toName}
                    ariaLabel={toLabel}
                    value={to}
                    min={from || null}
                    onChange={(value) => onToChange({ target: { value } })}
                />
            </div>
        </div>
    );
}
