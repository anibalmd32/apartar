import { suggestSku } from '@/features/products/lib/sku';
import { type ProductDefaults, type ProductFormValues } from '@/types/product';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

export interface ProductFormData {
    [key: string]: string | number | number[] | string[];
    name: string;
    sku: string;
    slug: string;
    description: string;
    price: string;
    compare_at_price: string;
    down_payment: string;
    installments_count: number;
    frequency_days: number;
    stock: number;
    category_id: string;
    category_name: string;
    tag_ids: number[];
    tag_names: string[];
}

const PREVIEW_DEBOUNCE_MS = 300;

function initialData(defaults: ProductDefaults, product?: ProductFormValues | null): ProductFormData {
    return {
        name: product?.name ?? '',
        sku: product?.sku ?? suggestSku('', defaults.skuSequence ?? 1),
        slug: product?.slug ?? '',
        description: product?.description ?? '',
        price: product?.price ?? '',
        compare_at_price: product?.compareAtPrice ?? '',
        down_payment: product?.downPayment ?? '0.00',
        installments_count: product?.installmentsCount ?? defaults.installmentsCount,
        frequency_days: product?.frequencyDays ?? defaults.frequencyDays,
        stock: product?.stock ?? 0,
        category_id: product?.categoryId === null || product?.categoryId === undefined ? '' : String(product.categoryId),
        category_name: '',
        tag_ids: product ? [...product.tagIds] : [],
        tag_names: [],
    };
}

export function useProductForm(defaults: ProductDefaults, product?: ProductFormValues | null) {
    const form = useForm<ProductFormData>(initialData(defaults, product));

    const { data } = form;

    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            router.reload({
                only: ['plan'],
                data: {
                    price: data.price,
                    compare_at_price: data.compare_at_price,
                    down_payment: data.down_payment,
                    installments_count: data.installments_count,
                    frequency_days: data.frequency_days,
                },
            });
        }, PREVIEW_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [data.price, data.compare_at_price, data.down_payment, data.installments_count, data.frequency_days]);

    const submit = (specifications: string[], images: readonly File[] = []) => {
        form.transform((current) => ({ ...current, specifications, ...(images.length > 0 ? { images: [...images] } : {}) }));

        if (product) {
            form.put(`/backoffice/productos/${product.id}`, { preserveScroll: true });

            return;
        }

        form.post('/backoffice/productos', { preserveScroll: true, forceFormData: images.length > 0 });
    };

    return { ...form, submit };
}

export type ProductFieldsProps = Pick<ReturnType<typeof useProductForm>, 'data' | 'setData' | 'errors'>;
