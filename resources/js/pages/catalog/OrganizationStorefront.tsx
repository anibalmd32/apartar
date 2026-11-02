import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { StorefrontCatalogCard } from '@/features/catalog/components/StorefrontCatalogCard';
import CatalogLayout from '@/layouts/catalog-layout';
import { formatPhone } from '@/lib/identity-format';
import { type OrganizationStorefrontProps } from '@/types/catalog-view';
import { cilLibrary, cilLocationPin, cilPhone } from '@coreui/icons';

export default function OrganizationStorefront({ business, showsPlatformBadge, catalogs, profile }: OrganizationStorefrontProps) {
    const branded = business.brandingEnabled && business.logoUrl !== null;

    return (
        <CatalogLayout title={business.name} business={business} showsPlatformBadge={showsPlatformBadge}>
            <section className="lg:bg-accent/12 lg:dark:bg-accent/20 flex items-center gap-4 lg:gap-5 lg:rounded-3xl lg:px-10 lg:py-9">
                {branded ? (
                    <img src={business.logoUrl ?? ''} alt="" className="size-[88px] shrink-0 rounded-full object-contain max-lg:hidden" />
                ) : (
                    <Avatar name={business.name} tone="accent" size={88} className="max-lg:hidden" />
                )}
                <div className="flex min-w-0 flex-col gap-2">
                    <h1 className="tracking-heading text-[26px] leading-[1.3] font-extrabold lg:text-4xl">{business.name}</h1>
                    {profile?.description ? <p className="text-ink-2 text-[15px] leading-normal">{profile.description}</p> : null}
                    {profile?.address || profile?.phone ? (
                        <p className="text-ink-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[13px]">
                            {profile.address ? (
                                <span className="inline-flex items-center gap-1.5">
                                    <Icon icon={cilLocationPin} size={14} />
                                    {profile.address}
                                </span>
                            ) : null}
                            {profile.phone ? (
                                <a href={`tel:${profile.phone}`} className="inline-flex items-center gap-1.5 hover:underline">
                                    <Icon icon={cilPhone} size={14} />
                                    <span className="whitespace-nowrap">{formatPhone(profile.phone)}</span>
                                </a>
                            ) : null}
                        </p>
                    ) : null}
                </div>
            </section>

            <h2 className="text-[22px] leading-[1.3] font-bold">Catálogos</h2>

            {catalogs.length === 0 ? (
                <div className="bg-base-100 border-line shadow-card flex flex-col items-center gap-3 rounded-2xl border px-6 py-10 text-center">
                    <IconChip icon={cilLibrary} tone="neutral" size={48} />
                    <p className="text-h2">{business.name} todavía no tiene catálogos publicados.</p>
                    <p className="text-ink-3 text-sm">Vuelve pronto: aquí aparecerán sus campañas en cuanto las publique.</p>
                </div>
            ) : (
                <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:gap-5">
                    {catalogs.map((catalog) => (
                        <li key={catalog.slug}>
                            <StorefrontCatalogCard catalog={catalog} />
                        </li>
                    ))}
                </ul>
            )}
        </CatalogLayout>
    );
}
