import { Avatar } from '@/components/shell/avatar';
import { Icon } from '@/components/ui/icon';
import { ProductGridCard } from '@/features/catalog/components/ProductGridCard';
import { track } from '@/features/telemetry/hooks/useTelemetry';
import { type FeedItem } from '@/types/account';
import { cilArrowRight } from '@coreui/icons';

export function FeedList({ items, grouped = false }: { items: readonly FeedItem[]; grouped?: boolean }) {
    if (!grouped) {
        return <FeedGrid items={items.map((item, index) => ({ item, position: index + 1 }))} />;
    }

    const groups: { key: string; catalog: string; first: FeedItem; entries: { item: FeedItem; position: number }[] }[] = [];

    items.forEach((item, index) => {
        const catalog = `${item.businessName}:${item.product.catalogSlug}`;
        const last = groups[groups.length - 1];

        if (last !== undefined && last.catalog === catalog) {
            last.entries.push({ item, position: index + 1 });
        } else {
            groups.push({ key: `${catalog}:${index}`, catalog, first: item, entries: [{ item, position: index + 1 }] });
        }
    });

    return (
        <div className="flex flex-col gap-6">
            {groups.map((group) => (
                <section key={group.key} aria-label={`${group.first.businessName} · ${group.first.catalogName}`} className="flex flex-col gap-3">
                    <header className="flex items-center gap-3">
                        <Avatar name={group.first.businessName} tone="accent" size={36} />
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-[15px] font-bold">{group.first.businessName}</p>
                            <p className="text-ink-3 truncate text-xs">{group.first.catalogName}</p>
                        </div>
                        {group.first.catalogUrl ? (
                            <a
                                href={group.first.catalogUrl}
                                className="text-primary-ink inline-flex items-center gap-1 text-[13px] font-semibold hover:underline"
                            >
                                Ver catálogo
                                <Icon icon={cilArrowRight} size={14} />
                            </a>
                        ) : null}
                    </header>
                    <FeedGrid items={group.entries} />
                </section>
            ))}
        </div>
    );
}

function FeedGrid({ items }: { items: readonly { item: FeedItem; position: number }[] }) {
    return (
        <ul className="grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
            {items.map(({ item, position }) => (
                <li
                    key={`${item.businessName}:${item.product.catalogSlug}:${item.product.slug}`}
                    className="min-w-0"
                    onClickCapture={() =>
                        track('feed_product_clicked', {
                            catalogSlug: item.product.catalogSlug,
                            subjectSlug: item.product.slug,
                            payload: { position },
                        })
                    }
                >
                    <ProductGridCard product={{ ...item.product, isNew: item.isNew }} />
                </li>
            ))}
        </ul>
    );
}
