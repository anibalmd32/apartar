import { ScrollRow } from '@/components/ui/scroll-row';
import { cn } from '@/lib/utils';

export interface FilterTab<T extends string | null> {
    value: T;
    label: string;
    count?: number | null;
}

interface FilterTabsProps<T extends string | null> {
    tabs: readonly FilterTab<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
    fill?: boolean;
}

export function FilterTabs<T extends string | null>({ tabs, value, onChange, label, className, fill = false }: FilterTabsProps<T>) {
    return (
        <ScrollRow role="group" aria-label={label} activeKey={value ?? 'all'} className={cn('border-line flex gap-1 border-b', className)}>
            {tabs.map((tab) => {
                const active = tab.value === value;

                return (
                    <button
                        key={tab.value ?? 'all'}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            'touch:min-h-11 -mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors',
                            fill && 'max-sm:flex-1 max-sm:justify-center max-sm:gap-1.5 max-sm:px-1.5',
                            active
                                ? 'border-primary text-base-content font-bold'
                                : 'text-ink-3 hover:text-base-content border-transparent font-medium',
                        )}
                    >
                        {tab.label}
                        {tab.count !== undefined && tab.count !== null && tab.count > 0 ? (
                            <span className={cn('badge badge-count', active ? 'badge-solid badge-primary' : 'badge-neutral')}>
                                {tab.count}
                                <span className="sr-only"> en total</span>
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </ScrollRow>
    );
}
