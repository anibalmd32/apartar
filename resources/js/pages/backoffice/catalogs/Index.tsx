import { Alert } from '@/components/ui/alert';
import { type FilterTab, FilterTabs } from '@/components/ui/filter-tabs';
import { Icon } from '@/components/ui/icon';
import { SectionCard } from '@/components/ui/section-card';
import { CatalogCard } from '@/features/catalogs/components/CatalogCard';
import { LimitBanner } from '@/features/plan/components/LimitBanner';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type CatalogListItem } from '@/types/catalog';
import { cilPlus } from '@coreui/icons';
import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Tab = 'all' | 'published' | 'draft' | 'expired';

const TAB_LABELS: Record<Tab, string> = { all: 'Todos', published: 'Publicados', draft: 'Borradores', expired: 'Caducados' };

function inTab(catalog: CatalogListItem, tab: Tab): boolean {
    switch (tab) {
        case 'all':
            return true;
        case 'published':
            return catalog.visibility === 'listed' || catalog.visibility === 'unlisted';
        default:
            return catalog.visibility === tab;
    }
}

interface CatalogsPageProps {
    catalogs: CatalogListItem[];
    [key: string]: unknown;
}

export default function CatalogsIndex({ catalogs }: CatalogsPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [tab, setTab] = useState<Tab>('all');

    const tabs: FilterTab<Tab>[] = (Object.keys(TAB_LABELS) as Tab[]).map((value) => ({
        value,
        label: TAB_LABELS[value],
        count: catalogs.filter((catalog) => inTab(catalog, value)).length,
    }));
    const visible = catalogs.filter((catalog) => inTab(catalog, tab));

    return (
        <BackofficeLayout
            title="Catálogos"
            description="Tus campañas. Cada catálogo tiene su propio enlace: lo compartes por WhatsApp y tus clientes entran por ahí."
            actions={
                <Link className="btn btn-primary" href="/backoffice/catalogos/crear">
                    <Icon icon={cilPlus} />
                    Nuevo catálogo
                </Link>
            }
        >
            {errors.status ? <Alert tone="warning">{errors.status}</Alert> : null}

            <LimitBanner metric="catalogs" />

            {catalogs.length === 0 ? (
                <SectionCard>
                    <p className="text-ink-2 text-sm">
                        Todavía no tienes catálogos. Crea uno, añádele productos y publícalo: el enlace que te dé es lo que compartes con tus
                        clientes.
                    </p>
                </SectionCard>
            ) : (
                <>
                    <FilterTabs label="Estado" tabs={tabs} value={tab} onChange={setTab} />

                    {visible.length === 0 ? (
                        <p className="text-ink-3 text-sm">Ningún catálogo en esta pestaña.</p>
                    ) : (
                        <ul className="grid gap-4 sm:grid-cols-2 lg:gap-5 xl:grid-cols-3">
                            {visible.map((catalog) => (
                                <li key={catalog.id}>
                                    <CatalogCard catalog={catalog} />
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </BackofficeLayout>
    );
}
