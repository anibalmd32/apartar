import { type ProductDetailContent, type ProductOffer } from '@/features/catalog/components/ProductDetailView';
import { plainText, sanitizeRichText } from '@/lib/sanitize-html';
import { type FinancingPlanView, type ImageSet } from '@/types/product';

export const DEFAULT_FEW_LEFT = 10;

export interface PreviewSource {
    readonly name: string;
    readonly sku: string;
    readonly description: string;
    readonly stock: number;
    readonly categoryName: string;
    readonly specifications: readonly string[];
    readonly images: readonly ImageSet[];
    readonly plan: FinancingPlanView | null;
    readonly fewLeft?: number;
}

/**
 * @returns the product as the public page shows it, from the values typed in the form.
 */
export function previewContent(source: PreviewSource): ProductDetailContent {
    const stock = Number(source.stock) || 0;
    const fewLeft = source.fewLeft ?? DEFAULT_FEW_LEFT;
    const description = sanitizeRichText(source.description);

    return {
        name: source.name.trim() === '' ? 'Producto sin nombre' : source.name.trim(),
        categoryName: source.categoryName,
        sku: source.sku,
        images: source.images,
        description: plainText(description) === '' ? '' : description,
        specifications: source.specifications.filter((specification) => specification !== ''),
        inStock: stock > 0,
        stockLeft: stock > 0 && stock <= fewLeft ? stock : null,
    };
}

/**
 * @returns the price and the plan as the public page shows them, or null while the terms do not make a plan.
 */
export function previewOffer(plan: FinancingPlanView | null): ProductOffer | null {
    if (plan === null) {
        return null;
    }

    return {
        price: plan.total,
        compareAtPrice: plan.compareAtPrice,
        savingsPercent: plan.savingsPercent,
        downPayment: plan.downPayment,
        installmentAmount: plan.installmentAmount,
        lastInstallmentAmount: plan.hasUnevenLastInstallment ? plan.lastInstallmentAmount : null,
        installmentsCount: plan.installmentsCount,
        frequencyDays: plan.frequencyDays,
    };
}

/**
 * @returns what publishing would still ask for, in the words of the publish refusal.
 */
export function missingToPublish(source: PreviewSource): string[] {
    const missing: string[] = [];

    if (source.images.length === 0) {
        missing.push('al menos una foto');
    }

    if (source.categoryName.trim() === '') {
        missing.push('una categoría');
    }

    if (plainText(source.description) === '') {
        missing.push('la descripción');
    }

    if (source.plan === null) {
        missing.push('las condiciones de pago');
    }

    return missing;
}

/**
 * @returns the items joined as Spanish prose: "a, b y c".
 */
export function listOf(items: readonly string[]): string {
    if (items.length <= 1) {
        return items[0] ?? '';
    }

    return `${items.slice(0, -1).join(', ')} y ${items[items.length - 1]}`;
}
