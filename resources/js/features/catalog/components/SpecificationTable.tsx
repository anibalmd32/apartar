import { type SpecificationGroupView } from '@/types/product';

interface SpecificationTableProps {
    groups: readonly SpecificationGroupView[];
}

const GENERAL = 'General';

export function SpecificationTable({ groups }: SpecificationTableProps) {
    if (groups.length === 0) {
        return null;
    }

    return (
        <div className="border-line overflow-hidden rounded-xl border">
            {groups.map((group) => (
                <section key={group.group ?? GENERAL} className="last:[&_dl>div:last-child]:border-b-0">
                    <h3 className="bg-surface-2 text-ink-3 border-line border-b px-3.5 py-2 text-[11px] font-bold tracking-wide uppercase">
                        {group.group ?? GENERAL}
                    </h3>

                    <div className="overflow-x-auto">
                        <dl>
                            {group.items.map((item, index) => (
                                <div
                                    key={`${item.label}-${index}`}
                                    className="border-line grid grid-cols-1 gap-x-3 gap-y-0.5 border-b px-3.5 py-2.5 text-[13px] md:grid-cols-[160px_1fr]"
                                >
                                    <dt className="text-ink-3">{item.label}</dt>
                                    <dd className="font-semibold whitespace-pre-line">{item.value}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </section>
            ))}
        </div>
    );
}
