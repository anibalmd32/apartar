import { cn } from '@/lib/utils';
import { cilCalendar } from '@coreui/icons';
import { type ChangeEvent } from 'react';
import { Icon } from './icon';

interface DateRangeFieldProps {
    label: string;
    fromLabel: string;
    toLabel?: string;
    from: string;
    to: string;
    onFromChange: (event: ChangeEvent<HTMLInputElement>) => void;
    onToChange: (event: ChangeEvent<HTMLInputElement>) => void;
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
            <label className={box}>
                <Icon icon={cilCalendar} className="text-ink-3" />
                <span className="text-ink-3 shrink-0 text-[13px]">{fromLabel}</span>
                <input name={fromName} type="date" className="min-w-0 grow" value={from} onChange={onFromChange} />
            </label>
            <label className={box}>
                <span className="text-ink-3 shrink-0 text-[13px]">{toLabel}</span>
                <input name={toName} type="date" className="min-w-0 grow" value={to} onChange={onToChange} />
            </label>
        </div>
    );
}
