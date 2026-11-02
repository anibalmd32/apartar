import { Badge } from '@/components/ui/badge';
import { Icon } from '@/components/ui/icon';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { LAYAWAY_TONES } from '@/lib/status-tones';
import { type ReceiptLayawaySummary } from '@/types/receipt';
import { cilBasket } from '@coreui/icons';

export function ProductThumb({ imageUrl, size = 'size-11' }: { imageUrl?: string | null; size?: string }) {
    return imageUrl ? (
        <img src={imageUrl} alt="" loading="lazy" className={`border-line ${size} shrink-0 rounded-lg border object-cover`} />
    ) : (
        <span
            aria-hidden="true"
            className={`border-line bg-surface-2 text-ink-3 flex ${size} shrink-0 items-center justify-center rounded-lg border`}
        >
            <Icon icon={cilBasket} size={18} />
        </span>
    );
}

export function ReceiptLayawayCard({ layaway }: { layaway: ReceiptLayawaySummary }) {
    return (
        <section className="border-line bg-base-100 flex items-start gap-3 rounded-[14px] border p-3.5" aria-label="Apartado">
            <ProductThumb imageUrl={layaway.imageUrl} size="size-12" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="line-clamp-2 text-sm font-bold">{layaway.productName}</p>
                <p className="text-ink-3 text-xs">
                    <span className="whitespace-nowrap">{layaway.code}</span> · Te falta{' '}
                    <MoneyLabel money={layaway.remaining} className="text-base-content font-semibold" />
                </p>
                <Badge tone={LAYAWAY_TONES[layaway.status]} className="self-start">
                    {layaway.statusLabel}
                </Badge>
            </div>
        </section>
    );
}
