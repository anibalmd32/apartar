import { PlanBreakdown, PriceTag, type PlanOffer, type PriceOffer } from '@/features/catalog/components/PlanBreakdown';
import { ProductGallery } from '@/features/catalog/components/ProductGallery';
import { richTextContentClass } from '@/lib/rich-text';
import { cn } from '@/lib/utils';
import { type ProductDetail } from '@/types/catalog-view';
import { type ReactNode } from 'react';

export type ProductDetailContent = Pick<
    ProductDetail,
    'name' | 'categoryName' | 'sku' | 'images' | 'description' | 'specifications' | 'inStock' | 'stockLeft'
>;

export type ProductOffer = PriceOffer & PlanOffer;

interface ProductDetailViewProps {
    product: ProductDetailContent;
    offer: ProductOffer | null;
    reserve: ReactNode;
    children?: ReactNode;
}

function StockState({ product }: { product: ProductDetailContent }) {
    return (
        <p className="text-ink-2 flex items-center gap-2 text-[13px] font-semibold">
            <span aria-hidden="true" className={cn('size-2 rounded-full', product.inStock ? 'bg-success' : 'bg-error')} />
            {product.inStock ? 'Disponible' : 'Agotado'}
            {product.inStock && product.stockLeft ? (
                <span className="text-ink-3 font-normal">
                    · {product.stockLeft === 1 ? 'queda 1 unidad' : `quedan ${product.stockLeft} unidades`}
                </span>
            ) : null}
        </p>
    );
}

function ProductDetails({ product }: { product: ProductDetailContent }) {
    if (product.description === '' && product.specifications.length === 0) {
        return null;
    }

    return (
        <div className="border-line flex max-w-[72ch] flex-col gap-6 border-t pt-6">
            {product.description !== '' ? (
                <section aria-labelledby="description-heading" className="flex flex-col gap-4">
                    <h2 id="description-heading" className="text-[17px] font-bold">
                        Descripción
                    </h2>
                    <div
                        className={cn('text-ink-2 text-[15px] leading-relaxed', richTextContentClass)}
                        dangerouslySetInnerHTML={{ __html: product.description }}
                    />
                </section>
            ) : null}

            {product.specifications.length > 0 ? (
                <section aria-labelledby="specs-heading" className="flex flex-col gap-4">
                    <h2 id="specs-heading" className="text-[17px] font-bold">
                        Características
                    </h2>
                    <ul className="text-ink-2 flex list-disc flex-col gap-1.5 pl-5 text-[15px] leading-relaxed">
                        {product.specifications.map((specification, index) => (
                            <li key={`${index}-${specification}`}>{specification}</li>
                        ))}
                    </ul>
                </section>
            ) : null}
        </div>
    );
}

export function ProductDetailView({ product, offer, reserve, children }: ProductDetailViewProps) {
    return (
        <div className="@container flex flex-col gap-6">
            <article className="grid grid-cols-1 gap-6 @3xl:grid-cols-[620fr_612fr] @3xl:gap-12">
                <ProductGallery images={product.images} name={product.name} />

                <div className="flex min-w-0 flex-col gap-[18px]">
                    <header className="flex flex-col gap-2">
                        {product.categoryName !== '' ? <p className="text-ink-3 text-[0.8125rem]">{product.categoryName}</p> : null}
                        <h1 className="text-[1.75rem] leading-[1.15] font-extrabold [font-stretch:88%] @3xl:text-[2.25rem]">{product.name}</h1>
                    </header>

                    {offer !== null ? (
                        <>
                            <PriceTag product={offer} />
                            <PlanBreakdown product={offer} />
                        </>
                    ) : null}

                    {reserve}

                    <StockState product={product} />

                    {children}
                </div>
            </article>

            <ProductDetails product={product} />
        </div>
    );
}
