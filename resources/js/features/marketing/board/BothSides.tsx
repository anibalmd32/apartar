import { cn } from '@/lib/utils';
import { EXAMPLE_PRICE_CENTS, formatBs, formatUsd, layawaySum, shortDay } from './board-math';

interface BothSidesProps {
    today: string;
    rate: string | null;
}

type Tone = 'ink' | 'red' | 'blue' | 'green';

const TONE_TEXT: Record<Tone, string> = {
    ink: 'text-pz-ink',
    red: 'text-pz-red',
    blue: 'text-pz-blue',
    green: 'text-pz-green',
};

interface LedgerRow {
    name: string;
    product: string;
    status: string;
    amount: string;
    tone: Tone;
    highlight?: boolean;
}

function SellerLedger({ rows }: { rows: LedgerRow[] }) {
    return (
        <ul className="flex flex-col">
            {rows.map((row) => (
                <li
                    key={row.name}
                    className={cn('pz-rule-dashed grid grid-cols-[1fr_auto] items-baseline gap-x-4 gap-y-0.5 py-3', row.highlight && 'relative')}
                >
                    <span className="text-lg font-bold">
                        {row.name}
                        <span className="text-pz-ink-2 font-medium"> · {row.product}</span>
                    </span>
                    <span className={cn('text-right text-lg font-bold tabular-nums', TONE_TEXT[row.tone])}>{row.amount}</span>
                    <span className={cn('font-hand col-span-2 text-xl leading-tight', TONE_TEXT[row.tone])}>{row.status}</span>
                </li>
            ))}
        </ul>
    );
}

function CustomerView({ today, rate }: BothSidesProps) {
    const sum = layawaySum(3, today);
    const paidCents = EXAMPLE_PRICE_CENTS - sum.remainingCents;
    const next = sum.rows.find((row) => !row.paid);

    return (
        <div className="flex flex-col gap-4">
            <p className="font-vinyl text-[2.5rem] normal-case sm:text-5xl">Hola, María</p>
            <p className="text-pz-ink-2 text-lg">Tu apartado del vestido de lino</p>

            <div className="flex gap-1.5" role="img" aria-label={`Pagado ${formatUsd(paidCents)} de ${formatUsd(EXAMPLE_PRICE_CENTS)}`}>
                {sum.rows.map((row) => (
                    <span
                        key={row.label}
                        className={cn('h-3.5 flex-1 rounded-full', row.paid ? 'bg-pz-green' : 'shadow-[inset_0_0_0_2px_var(--pz-rule)]')}
                    />
                ))}
            </div>

            <dl className="grid grid-cols-2 gap-x-6 gap-y-1 text-lg">
                <dt className="text-pz-ink-2">Pagado</dt>
                <dd className="text-pz-green text-right font-bold tabular-nums">{formatUsd(paidCents)}</dd>
                <dt className="text-pz-ink-2">Resta</dt>
                <dd className="text-pz-red text-right font-bold tabular-nums">{formatUsd(sum.remainingCents)}</dd>
                {next?.due ? (
                    <>
                        <dt className="text-pz-ink-2">Próxima cuota</dt>
                        <dd className="text-right font-bold tabular-nums">
                            {formatUsd(next.cents)} · {shortDay(next.due)}
                        </dd>
                    </>
                ) : null}
            </dl>

            {rate === null || next === undefined ? null : (
                <p className="font-hand text-pz-blue text-xl">Si pagas hoy en bolívares: {formatBs(next.cents, rate)}</p>
            )}

            <span aria-hidden="true" className="pz-btn pz-btn-line self-start">
                Subir comprobante
            </span>
        </div>
    );
}

export function BothSides({ today, rate }: BothSidesProps) {
    const sum = layawaySum(3, today);
    const next = sum.rows.find((row) => !row.paid);

    const rows: LedgerRow[] = [
        {
            name: 'María Gómez',
            product: 'Vestido de lino',
            status: next?.due ? `próxima cuota el ${shortDay(next.due)}` : 'al día',
            amount: `resta ${formatUsd(sum.remainingCents)}`,
            tone: 'ink',
            highlight: true,
        },
        { name: 'José Rivas', product: 'Bolso de cuero', status: 'mandó un comprobante: por aprobar', amount: '$20,00', tone: 'blue' },
        { name: 'Carla Méndez', product: 'Sandalias', status: 'cuota vencida hace 3 días', amount: '$15,00', tone: 'red' },
        { name: 'Ana Torres', product: 'Perfume', status: 'pagado completo, listo para entregar', amount: '$0,00', tone: 'green' },
    ];

    return (
        <section aria-labelledby="misma-cuenta" className="mx-auto max-w-[1280px] px-4 py-16 sm:px-6 lg:px-12 lg:py-24">
            <h2 id="misma-cuenta" className="font-vinyl max-w-[14ch] text-[2.75rem] sm:text-6xl lg:text-[4.5rem]">
                Tú y tu clienta ven <span className="text-pz-blue">la misma</span> cuenta
            </h2>
            <p className="text-pz-ink-2 mt-5 max-w-[56ch] text-lg leading-relaxed">
                Lo que ella pagó, lo que le falta y cuándo vence su próxima cuota. Sin capturas perdidas en el chat y sin cuaderno.
            </p>

            <div className="mt-12 grid gap-12 lg:grid-cols-[1.1fr_auto_1fr] lg:gap-10">
                <div>
                    <p className="font-hand text-pz-ink-3 mb-2 text-2xl">En tu panel</p>
                    <SellerLedger rows={rows} />
                </div>
                <div aria-hidden="true" className="bg-pz-rule hidden w-0.5 rounded-full lg:block" />
                <div>
                    <p className="font-hand text-pz-ink-3 mb-2 text-2xl">En el teléfono de María</p>
                    <CustomerView today={today} rate={rate} />
                </div>
            </div>
            <p className="font-hand text-pz-ink-3 mt-8 text-lg">Nombres y montos de ejemplo.</p>
        </section>
    );
}
