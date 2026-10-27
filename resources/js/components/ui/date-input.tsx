import { formatIsoDate, isWithin, todayIso } from '@/lib/iso-date';
import { cn } from '@/lib/utils';
import { cilCalendar } from '@coreui/icons';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Calendar } from './calendar';
import { Icon } from './icon';

export interface DateInputProps {
    value: string;
    onChange: (value: string) => void;
    id?: string;
    name?: string;
    min?: string | null;
    max?: string | null;
    required?: boolean;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
    ariaLabel?: string;
    placeholder?: string;
    bare?: boolean;
    className?: string;
}

export function DateInput({
    value,
    onChange,
    id,
    name,
    min,
    max,
    required = false,
    disabled = false,
    invalid = false,
    describedBy,
    ariaLabel,
    placeholder = 'dd/mm/aaaa',
    bare = false,
    className,
}: DateInputProps) {
    const shown = formatIsoDate(value);
    const today = todayIso();

    return (
        <Popover className={cn('relative', bare ? 'min-w-0 grow' : 'w-full')}>
            {name ? <input type="hidden" name={name} value={value} /> : null}
            <PopoverButton
                id={id}
                disabled={disabled}
                aria-label={ariaLabel ? `${ariaLabel}: ${shown ?? 'sin fecha'}` : undefined}
                aria-invalid={invalid ? true : undefined}
                aria-describedby={describedBy}
                aria-required={required ? true : undefined}
                className={cn(
                    'flex cursor-pointer items-center gap-2 text-left',
                    bare ? 'w-full min-w-0' : cn('input w-full', invalid && 'input-error'),
                    className,
                )}
            >
                <span className={cn('min-w-0 grow truncate', shown ? 'text-base-content' : 'text-ink-3')}>{shown ?? placeholder}</span>
                {bare ? null : <Icon icon={cilCalendar} size={16} className="text-ink-3 shrink-0" />}
            </PopoverButton>
            <PopoverPanel
                anchor={{ to: 'bottom start', gap: 6 }}
                className="bg-base-100 border-line shadow-pop z-50 rounded-2xl border p-3 focus:outline-none"
            >
                {({ close }) => (
                    <div className="flex flex-col gap-2">
                        <Calendar
                            value={value === '' ? null : value}
                            min={min}
                            max={max}
                            onSelect={(picked) => {
                                onChange(picked);
                                close();
                            }}
                        />
                        <div className="border-line flex justify-between border-t pt-2">
                            {!required && value !== '' ? (
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm"
                                    onClick={() => {
                                        onChange('');
                                        close();
                                    }}
                                >
                                    Borrar
                                </button>
                            ) : (
                                <span />
                            )}
                            {isWithin(today, min, max) ? (
                                <button
                                    type="button"
                                    className="btn btn-ghost btn-sm text-primary-ink"
                                    onClick={() => {
                                        onChange(today);
                                        close();
                                    }}
                                >
                                    Hoy
                                </button>
                            ) : null}
                        </div>
                    </div>
                )}
            </PopoverPanel>
        </Popover>
    );
}
