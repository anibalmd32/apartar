import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilBasket, cilCheckCircle, cilDiamond, cilGift, cilTags } from '@coreui/icons';

const PRODUCTS = [
    { name: 'Bolso tote', price: '$30 + 4×$30', icon: cilBasket, tone: 'bg-[#e8d5c4] dark:bg-[#3a2f27]' },
    { name: 'Sandalias', price: '$20 + 4×$35', icon: cilTags, tone: 'bg-[#c9d7e8] dark:bg-[#27303a]' },
    { name: 'Vestido lino', price: '$25 + 3×$20', icon: cilGift, tone: 'bg-[#d9e8c9] dark:bg-[#2c3a27]' },
    { name: 'Collar perlas', price: '$40 + 6×$30', icon: cilDiamond, tone: 'bg-[#e8c9d9] dark:bg-[#3a2733]' },
];

const floating = 'bg-base-100 absolute rounded-2xl shadow-[0_16px_40px_rgb(21_26_45/0.22)] dark:shadow-[0_16px_40px_rgb(0_0_0/0.5)]';

export function HeroVisual({ className }: { className?: string }) {
    return (
        <div aria-hidden="true" className={cn('bg-primary-soft relative flex items-center justify-center rounded-[32px] py-[88px]', className)}>
            <div className="border-neutral bg-base-100 dark:border-line-strong flex w-[250px] flex-col gap-2.5 rounded-[36px] border-[6px] px-3.5 py-4 shadow-[0_16px_40px_rgb(21_26_45/0.22)] sm:w-[290px]">
                <div className="flex items-center gap-2">
                    <span className="bg-accent/13 text-accent dark:bg-accent/28 flex size-7 items-center justify-center rounded-full text-[11px] font-bold dark:text-white">
                        BM
                    </span>
                    <div className="leading-tight">
                        <p className="text-xs font-bold">Boutique Mariela</p>
                        <p className="text-ink-3 text-[10px]">Otoño 2026 · 24 productos</p>
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-2">
                    {PRODUCTS.map((product) => (
                        <div key={product.name} className="flex flex-col gap-1">
                            <div className={cn('text-ink-2 flex h-[92px] items-center justify-center rounded-[14px]', product.tone)}>
                                <Icon icon={product.icon} size={33} className="opacity-60" />
                            </div>
                            <p className="text-[11px] font-semibold">{product.name}</p>
                            <p className="text-primary-ink text-[10px] font-semibold">{product.price}</p>
                        </div>
                    ))}
                </div>

                <span className="bg-primary text-primary-content flex h-8 items-center justify-center rounded-[10px] text-[13px] font-semibold">
                    Apartar
                </span>
            </div>

            <div className={cn(floating, 'top-5 left-0 flex flex-col gap-1 px-3.5 py-2.5')}>
                <p className="text-ink-3 text-[11px]">Mariela compartió un enlace</p>
                <p className="text-primary-ink text-[13px] font-bold">apartar.store/c/otono-2026</p>
            </div>

            <div className={cn(floating, 'right-0 bottom-8 flex items-center gap-2 px-3.5 py-2.5 sm:right-[2px]')}>
                <span className="bg-success-soft text-success flex size-7 items-center justify-center rounded-lg">
                    <Icon icon={cilCheckCircle} size={14} />
                </span>
                <div className="leading-tight">
                    <p className="text-xs font-bold">Nuevo apartado</p>
                    <p className="text-ink-3 text-[11px]">AP-7K3M9QRT · $30 de inicial</p>
                </div>
            </div>
        </div>
    );
}
