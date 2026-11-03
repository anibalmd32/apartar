import { AmountProgress } from '@/features/collections/components/AmountProgress';
import { MoneyLabel } from '@/features/currency/components/MoneyLabel';
import { type CatalogSummaryProduct } from '@/types/catalog-summary';
import { Link } from '@inertiajs/react';

function Units({ product }: { product: CatalogSummaryProduct }) {
    return (
        <span className="text-ink-2 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
            <span>
                <strong className="text-base-content">{product.stock}</strong> disponibles
            </span>
            <span>
                <strong className="text-base-content">{product.reserved}</strong> apartadas
            </span>
            <span>
                <strong className="text-base-content">{product.sold}</strong> pagadas
            </span>
        </span>
    );
}

export function SummaryProducts({ products }: { products: readonly CatalogSummaryProduct[] }) {
    if (products.length === 0) {
        return <p className="text-ink-2 text-sm">Este catálogo todavía no tiene productos.</p>;
    }

    return (
        <ul className="divide-line flex flex-col divide-y" aria-label="Productos del catálogo">
            {products.map((product) => (
                <li key={product.id} className="flex flex-wrap items-center gap-x-6 gap-y-3 py-3.5 first:pt-0 last:pb-0">
                    <span className="flex min-w-0 flex-1 basis-56 flex-col gap-1">
                        <Link href={product.url} className="hit-area truncate font-semibold hover:underline">
                            {product.name}
                        </Link>
                        <span className="text-ink-3 text-xs">
                            {product.sku} · <MoneyLabel money={product.price} compact />
                        </span>
                        <Units product={product} />
                    </span>
                    <AmountProgress paid={product.paid} total={product.committed} progress={product.progress} className="basis-48" />
                    <span className="flex flex-col items-end whitespace-nowrap">
                        <span className="text-ink-3 text-xs">Por cobrar</span>
                        <MoneyLabel money={product.remaining} className="font-semibold" compact />
                    </span>
                </li>
            ))}
        </ul>
    );
}
