import { SectionCard } from '@/components/ui/section-card';
import { type MailCounts } from '@/types/mail';
import { count, percent } from './format';

interface BreakdownRow extends MailCounts {
    readonly key: string;
    readonly label: string;
}

const rate = (part: number, whole: number) => (whole === 0 ? null : part / whole);

interface BreakdownTableProps {
    title: string;
    heading: string;
    subtitle?: string;
    rows: readonly BreakdownRow[];
    empty: string;
}

export function BreakdownTable({ title, heading, subtitle, rows, empty }: BreakdownTableProps) {
    return (
        <SectionCard title={title} subtitle={subtitle} aria-label={title} flush>
            {rows.length === 0 ? (
                <p className="text-ink-3 px-5 pb-5 text-sm">{empty}</p>
            ) : (
                <>
                    <ul className="divide-line border-line flex flex-col divide-y border-t md:hidden" aria-label={title}>
                        {rows.map((row) => (
                            <li key={row.key} className="flex flex-col gap-2 px-5 py-3">
                                <p className="truncate text-sm font-semibold" title={row.label}>
                                    {row.label}
                                </p>
                                <dl className="grid grid-cols-4 gap-2 text-[13px]">
                                    {[
                                        ['Enviados', count(row.sent)],
                                        ['Entrega', percent(rate(row.delivered, row.sent))],
                                        ['Apertura', percent(rate(row.opened, row.delivered))],
                                        ['Rebotes', count(row.bounced)],
                                    ].map(([label, value]) => (
                                        <div key={label} className="flex flex-col">
                                            <dt className="text-ink-3 text-xs">{label}</dt>
                                            <dd className="font-medium tabular-nums">{value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            </li>
                        ))}
                    </ul>
                    <div className="hidden overflow-x-auto md:block">
                        <table className="table-sm table">
                            <thead>
                                <tr>
                                    <th scope="col">{heading}</th>
                                    <th scope="col" className="text-right">
                                        Enviados
                                    </th>
                                    <th scope="col" className="text-right">
                                        Entrega
                                    </th>
                                    <th scope="col" className="text-right">
                                        Apertura
                                    </th>
                                    <th scope="col" className="text-right">
                                        Rebotes
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.key}>
                                        <th scope="row" className="max-w-[220px] truncate font-medium" title={row.label}>
                                            {row.label}
                                        </th>
                                        <td className="text-right tabular-nums">{count(row.sent)}</td>
                                        <td className="text-right tabular-nums">{percent(rate(row.delivered, row.sent))}</td>
                                        <td className="text-right tabular-nums">{percent(rate(row.opened, row.delivered))}</td>
                                        <td className="text-right tabular-nums">{count(row.bounced)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </SectionCard>
    );
}
