import { isWithin, parseIsoDate, shiftIsoDate, todayIso, toIsoDate } from '@/lib/iso-date';
import { cn } from '@/lib/utils';
import { cilChevronLeft, cilChevronRight } from '@coreui/icons';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { Icon } from './icon';

interface CalendarProps {
    value: string | null;
    onSelect: (value: string) => void;
    min?: string | null;
    max?: string | null;
}

const WEEKDAYS = [
    ['L', 'lunes'],
    ['M', 'martes'],
    ['X', 'miércoles'],
    ['J', 'jueves'],
    ['V', 'viernes'],
    ['S', 'sábado'],
    ['D', 'domingo'],
] as const;

const MONTH = new Intl.DateTimeFormat('es-VE', { month: 'long', year: 'numeric' });
const DAY_LABEL = new Intl.DateTimeFormat('es-VE', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

const KEY_STEPS: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function startingFocus(value: string | null, min?: string | null, max?: string | null): string {
    const today = todayIso();
    const candidate = value ?? today;

    if (min && candidate < min) {
        return min;
    }

    return max && candidate > max ? max : candidate;
}

export function Calendar({ value, onSelect, min, max }: CalendarProps) {
    const [focused, setFocused] = useState(() => startingFocus(value, min, max));
    const grid = useRef<HTMLDivElement>(null);
    const moved = useRef(false);
    const view = parseIsoDate(focused) ?? { year: new Date().getFullYear(), month: new Date().getMonth() + 1, day: 1 };
    const today = todayIso();
    const offset = (new Date(view.year, view.month - 1, 1).getDay() + 6) % 7;
    const length = new Date(view.year, view.month, 0).getDate();
    const cells = [
        ...Array.from({ length: offset }, () => null),
        ...Array.from({ length }, (_, index) => toIsoDate(view.year, view.month, index + 1)),
    ];

    useEffect(() => {
        if (moved.current) {
            grid.current?.querySelector<HTMLButtonElement>(`[data-iso="${focused}"]`)?.focus();
        }
    }, [focused]);

    const move = (target: string) => {
        moved.current = true;
        setFocused(target);
    };

    const changeMonth = (step: number) => {
        moved.current = false;
        setFocused(toIsoDate(view.year, view.month + step, Math.min(view.day, new Date(view.year, view.month - 1 + step + 1, 0).getDate())));
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        const step = KEY_STEPS[event.key];
        const weekday = (new Date(view.year, view.month - 1, view.day).getDay() + 6) % 7;
        const target =
            step !== undefined
                ? shiftIsoDate(focused, step)
                : event.key === 'Home'
                  ? shiftIsoDate(focused, -weekday)
                  : event.key === 'End'
                    ? shiftIsoDate(focused, 6 - weekday)
                    : event.key === 'PageUp' || event.key === 'PageDown'
                      ? toIsoDate(view.year, view.month + (event.key === 'PageUp' ? -1 : 1), view.day)
                      : null;

        if (target !== null) {
            event.preventDefault();
            move(target);
        }
    };

    return (
        <div className="flex w-[272px] flex-col gap-2">
            <div className="flex items-center justify-between">
                <button type="button" className="btn btn-ghost btn-sm btn-square" aria-label="Mes anterior" onClick={() => changeMonth(-1)}>
                    <Icon icon={cilChevronLeft} size={16} />
                </button>
                <p className="text-sm font-bold" aria-live="polite">
                    {capitalize(MONTH.format(new Date(view.year, view.month - 1, 1)))}
                </p>
                <button type="button" className="btn btn-ghost btn-sm btn-square" aria-label="Mes siguiente" onClick={() => changeMonth(1)}>
                    <Icon icon={cilChevronRight} size={16} />
                </button>
            </div>
            <div ref={grid} role="grid" aria-label="Calendario" className="grid grid-cols-7 gap-0.5" onKeyDown={onKeyDown}>
                {WEEKDAYS.map(([short, long]) => (
                    <abbr key={long} title={long} role="columnheader" className="text-ink-3 py-1 text-center text-[11px] font-bold no-underline">
                        {short}
                    </abbr>
                ))}
                {cells.map((iso, index) => {
                    if (iso === null) {
                        return <span key={`blank-${index}`} aria-hidden="true" />;
                    }

                    const parts = parseIsoDate(iso);
                    const selectable = isWithin(iso, min, max);
                    const selected = iso === value;

                    return (
                        <button
                            key={iso}
                            type="button"
                            role="gridcell"
                            data-iso={iso}
                            tabIndex={iso === focused ? 0 : -1}
                            disabled={!selectable}
                            aria-selected={selected}
                            aria-current={iso === today ? 'date' : undefined}
                            aria-label={parts ? DAY_LABEL.format(new Date(parts.year, parts.month - 1, parts.day)) : iso}
                            onClick={() => onSelect(iso)}
                            onFocus={() => setFocused(iso)}
                            className={cn(
                                'flex h-9 items-center justify-center rounded-lg text-[13px] transition-colors disabled:cursor-not-allowed disabled:opacity-35',
                                selected
                                    ? 'bg-primary text-primary-content font-bold'
                                    : iso === today
                                      ? 'text-primary-ink ring-primary/40 font-bold ring-1 ring-inset'
                                      : 'hover:bg-surface-2 text-base-content',
                            )}
                        >
                            {parts?.day}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
