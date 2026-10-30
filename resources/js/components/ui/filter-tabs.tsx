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
}

export function FilterTabs<T extends string | null>({ tabs, value, onChange, label, className }: FilterTabsProps<T>) {
    return (
        <div
            role="group"
            aria-label={label}
            className={cn(
                'border-line flex gap-1 overflow-x-auto overflow-y-hidden border-b [scrollbar-width:none] [&::-webkit-scrollbar]:hidden',
                className,
            )}
        >
            {tabs.map((tab) => {
                const active = tab.value === value;

                return (
                    <button
                        key={tab.value ?? 'all'}
                        type="button"
                        aria-pressed={active}
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            '-mb-px flex shrink-0 items-center gap-2 border-b-2 px-3 py-2.5 text-sm whitespace-nowrap transition-colors',
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
        </div>
    );
}
