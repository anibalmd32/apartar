import { PrimaryAmount } from '@/features/catalog/components/PrimaryAmount';
import { type ProductDetail } from '@/types/catalog-view';
import { Link } from '@inertiajs/react';

interface ReserveBarProps {
    product: Pick<ProductDetail, 'name' | 'price' | 'downPayment'>;
    href: string;
}

export function ReserveBar({ product, href }: ReserveBarProps) {
    const withoutDownPayment = Number(product.downPayment.cents) === 0;

    return (
        <div
            data-bottom-bar
            role="region"
            aria-label="Apartar este producto"
            className="bg-base-100/95 border-line fixed inset-x-0 bottom-0 z-40 border-t px-4 pt-3 pb-[max(env(safe-area-inset-bottom),0.75rem)] backdrop-blur lg:hidden"
        >
            <div className="mx-auto flex h-14 max-w-xl items-center gap-3">
                <div className="flex min-w-0 flex-1 flex-col">
                    <PrimaryAmount money={product.price} className="truncate text-lg leading-tight font-extrabold" />
                    <span className="text-ink-2 truncate text-xs">
                        {withoutDownPayment ? (
                            'Sin inicial'
                        ) : (
                            <>
                                Inicial <PrimaryAmount money={product.downPayment} className="text-base-content font-semibold" />
                            </>
                        )}
                    </span>
                </div>
                <Link href={href} className="btn btn-primary h-12 min-w-36 rounded-xl px-6 text-base">
                    Apartar
                </Link>
            </div>
        </div>
    );
}
