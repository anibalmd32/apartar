import { Avatar } from '@/components/shell/avatar';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { Icon } from '@/components/ui/icon';
import { LiveRateStamp } from '@/features/catalog/components/LiveRateStamp';
import { type LiveRate } from '@/features/catalog/hooks/useLiveRate';
import { type OrganizationBrand } from '@/types/backoffice';
import { cilUser } from '@coreui/icons';
import { type CSSProperties } from 'react';

interface ShopHeaderProps {
    business: OrganizationBrand;
    live: LiveRate;
    timezone: string;
}

export function ShopHeader({ business, live, timezone }: ShopHeaderProps) {
    const branded = business.brandingEnabled;
    const accent = branded && business.brandColor !== null ? ({ borderTopColor: business.brandColor } as CSSProperties) : undefined;

    return (
        <header className={`bg-base-100 border-line border-b ${accent ? 'border-t-4' : ''}`} style={accent}>
            <div className="mx-auto flex max-w-[1440px] flex-wrap items-center gap-x-3 gap-y-1 px-4 py-3 md:min-h-[60px] lg:min-h-[72px] lg:px-20">
                {branded && business.logoUrl !== null ? (
                    <img src={business.logoUrl} alt={business.name} className="size-[34px] shrink-0 rounded-full object-contain lg:size-10" />
                ) : (
                    <Avatar name={business.name} tone="accent" className="size-[34px]! text-[13px]! lg:size-10! lg:text-[15px]!" />
                )}

                <div className="min-w-0 flex-1">
                    <p className="truncate text-[15px] font-bold lg:text-base">{business.name}</p>
                    {business.tagline ? <p className="text-ink-3 truncate text-xs max-sm:hidden">{business.tagline}</p> : null}
                </div>

                <LiveRateStamp
                    live={live}
                    timezone={timezone}
                    className="max-md:order-last max-md:min-h-0 max-md:basis-full max-md:justify-center max-md:border-0 max-md:py-0"
                />
                <CurrencySwitch />

                <a href="/mi-cuenta" className="btn border-line max-lg:btn-square lg:border-line-strong max-lg:size-9 max-lg:rounded-[10px]">
                    <Icon icon={cilUser} />
                    <span className="max-lg:sr-only">Mi cuenta</span>
                </a>
            </div>
        </header>
    );
}
