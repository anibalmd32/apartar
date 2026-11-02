import { type SearchGroup, type SearchItem } from '@/components/shell/use-global-search';
import { cn } from '@/lib/utils';

interface SearchResultsProps {
    term: string;
    groups: readonly SearchGroup[];
    active: number;
    onHover: (position: number) => void;
    onPick: (item: SearchItem) => void;
    itemClassName?: string;
}

export function SearchResults({ term, groups, active, onHover, onPick, itemClassName }: SearchResultsProps) {
    let index = -1;

    return (
        <>
            {term.trim().length >= 2 && groups.length === 0 ? <p className="text-ink-3 text-[13px]">Nada coincide con «{term.trim()}».</p> : null}

            {groups.map((group) => (
                <section key={group.key} aria-label={group.label} className="flex flex-col gap-1">
                    <h3 className="text-eyebrow text-ink-3">{group.label}</h3>
                    <ul className="flex flex-col">
                        {group.items.map((item) => {
                            index += 1;
                            const position = index;

                            return (
                                <li key={item.url}>
                                    <button
                                        type="button"
                                        onClick={() => onPick(item)}
                                        onMouseEnter={() => onHover(position)}
                                        className={cn(
                                            'flex w-full cursor-pointer flex-col rounded-lg px-3 py-2 text-left',
                                            position === active ? 'bg-primary-soft' : 'hover:bg-surface-2',
                                            itemClassName,
                                        )}
                                    >
                                        <span className="text-sm font-semibold">{item.title}</span>
                                        {item.detail ? <span className="text-ink-3 text-xs">{item.detail}</span> : null}
                                    </button>
                                </li>
                            );
                        })}
                    </ul>
                </section>
            ))}
        </>
    );
}
