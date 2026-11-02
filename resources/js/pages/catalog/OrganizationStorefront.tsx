import { Icon } from '@/components/ui/icon';
import { IconChip } from '@/components/ui/icon-chip';
import { StorefrontCatalogCard } from '@/features/catalog/components/StorefrontCatalogCard';
import { whatsappChatUrl } from '@/features/catalog/lib/whatsapp';
import CatalogLayout from '@/layouts/catalog-layout';
import { formatPhone } from '@/lib/identity-format';
import { type OrganizationStorefrontProps } from '@/types/catalog-view';
import { cilChatBubble, cilLibrary, cilLocationPin, cilPhone } from '@coreui/icons';

function Contact({ profile }: { profile: NonNullable<OrganizationStorefrontProps['profile']> }) {
    const whatsapp = profile.phone ? whatsappChatUrl(profile.phone) : null;

    if (!profile.address && !profile.phone) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-[13px]">
            {whatsapp !== null ? (
                <a href={whatsapp} target="_blank" rel="noopener noreferrer" className="btn btn-primary btn-sm h-10 rounded-full px-4">
                    <Icon icon={cilChatBubble} size={16} />
                    Escribir por WhatsApp
                </a>
            ) : null}
            {profile.phone ? (
                <a href={`tel:${profile.phone}`} className="hit-area text-ink-2 inline-flex items-center gap-1.5 hover:underline">
                    <Icon icon={cilPhone} size={14} />
                    <span className="whitespace-nowrap">{formatPhone(profile.phone)}</span>
                </a>
            ) : null}
            {profile.address ? (
                <span className="text-ink-2 inline-flex items-center gap-1.5">
                    <Icon icon={cilLocationPin} size={14} />
                    {profile.address}
                </span>
            ) : null}
        </div>
    );
}

export default function OrganizationStorefront({ business, showsPlatformBadge, catalogs, profile }: OrganizationStorefrontProps) {
    return (
        <CatalogLayout title={business.name} business={business} showsPlatformBadge={showsPlatformBadge}>
            <section className="lg:bg-accent/12 lg:dark:bg-accent/20 flex flex-col gap-3 lg:rounded-3xl lg:px-10 lg:py-9">
                <h1 className="tracking-heading text-[26px] leading-[1.3] font-extrabold lg:text-4xl">{business.name}</h1>
                {profile?.description ? <p className="text-ink-2 max-w-2xl text-[15px] leading-normal">{profile.description}</p> : null}
                {profile ? <Contact profile={profile} /> : null}
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
