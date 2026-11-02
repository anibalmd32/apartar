import { Chip } from '@/components/ui/chip';
import { type FilterTab } from '@/components/ui/filter-tabs';
import { ScrollRow } from '@/components/ui/scroll-row';
import { cn } from '@/lib/utils';

interface QuickFiltersProps<T extends string | null> {
    tabs: readonly FilterTab<T>[];
    value: T;
    onChange: (value: T) => void;
    label: string;
    className?: string;
}

export function QuickFilters<T extends string | null>({ tabs, value, onChange, label, className }: QuickFiltersProps<T>) {
    return (
        <ScrollRow
            role="group"
            aria-label={label}
            activeKey={value ?? 'all'}
            className={cn('flex gap-2 lg:flex-wrap lg:overflow-visible', className)}
        >
            {tabs.map((tab) => (
                <Chip
                    key={tab.value ?? 'all'}
                    active={tab.value === value}
                    count={tab.count !== undefined && tab.count !== null && tab.count > 0 ? tab.count : undefined}
                    onClick={() => onChange(tab.value)}
                >
                    {tab.label}
                </Chip>
            ))}
        </ScrollRow>
    );
}
