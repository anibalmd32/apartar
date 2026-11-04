import { Avatar } from '@/components/shell/avatar';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { RateStrip, RateText } from '@/components/shell/rate-strip';
import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Icon } from '@/components/ui/icon';
import { type OrganizationBrand } from '@/types/backoffice';
import { type RateView } from '@/types/exchange';
import { cilUser } from '@coreui/icons';

export function ShopHeader({ business, rate }: { business: OrganizationBrand; rate?: RateView }) {
    const logo = business.brandingEnabled ? business.logoUrl : null;

    const identity = (
        <>
            {logo !== null ? (
                <img src={logo} alt="" className="border-line size-11 shrink-0 rounded-xl border object-contain lg:size-12" />
            ) : (
                <Avatar name={business.name} tone="neutral" className="size-11! rounded-xl! text-[15px]! lg:size-12!" />
            )}
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-[1.0625rem] leading-tight font-extrabold [font-stretch:90%] lg:text-xl">{business.name}</span>
                {business.tagline ? <span className="text-ink-3 truncate text-[0.8125rem] leading-snug">{business.tagline}</span> : null}
            </span>
        </>
    );

    return (
        <header className="bg-base-100 border-line border-b">
            <div className="mx-auto flex max-w-[1440px] items-center gap-2 px-4 py-3 sm:gap-3 lg:min-h-[76px] lg:px-20">
                {business.storefrontUrl ? (
                    <a
                        href={business.storefrontUrl}
                        className="flex min-w-0 flex-1 items-center gap-3"
                        aria-label={`${business.name}, ver la tienda`}
                    >
                        {identity}
                    </a>
                ) : (
                    <div className="flex min-w-0 flex-1 items-center gap-3">{identity}</div>
                )}

                <RateText rate={rate} className="max-md:hidden" />
                <CurrencySwitch className="max-md:hidden" />
                <CurrencySwitch compact className="md:hidden" />
                <ThemeToggle />

                <a href="/mi-cuenta" className="btn btn-ghost touch:min-h-11 shrink-0 gap-2 px-3" aria-label="Mis apartados">
                    <Icon icon={cilUser} size={18} />
                    <span className="max-sm:hidden">Mis apartados</span>
                </a>
            </div>
            <RateStrip rate={rate} className="md:hidden" />
        </header>
    );
}
