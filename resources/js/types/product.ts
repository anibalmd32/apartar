import { type MoneyView } from '@/types/money';

export type ProductStatus = 'draft' | 'published' | 'archived';

export interface ProductStatusDefinition {
    readonly value: ProductStatus;
    readonly label: string;
}

export interface TaxonomyOption {
    readonly id: number;
    readonly name: string;
}

export interface FinancingPlanView {
    readonly downPayment: MoneyView;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly installmentAmount: MoneyView;
    readonly lastInstallmentAmount: MoneyView;
    readonly hasUnevenLastInstallment: boolean;
    readonly financedAmount: MoneyView;
    readonly total: MoneyView;
    readonly compareAtPrice: MoneyView | null;
    readonly savingsPercent: number | null;
}

export interface SpecificationFormRow {
    readonly key: string;
    readonly text: string;
}

export interface ImageSet {
    readonly thumb: string;
    readonly card: string;
    readonly zoom: string;
    readonly alt: string;
}

export interface ProductImageView {
    readonly id: number;
    readonly set: ImageSet;
    readonly isProcessing: boolean;
    readonly needsReview: boolean;
}

export interface ProductFormValues {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly slug: string;
    readonly description: string | null;
    readonly price: string;
    readonly compareAtPrice: string | null;
    readonly downPayment: string;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly stock: number;
    readonly stockBreakdown: StockBreakdownView;
    readonly status: ProductStatus;
    readonly categoryId: number | null;
    readonly tagIds: readonly number[];
    readonly specifications: readonly string[];
    readonly images: readonly ProductImageView[];
    readonly maxImages: number;
    readonly slugIsLocked: boolean;
    readonly updatedAt: string | null;
    readonly publicUrl?: string | null;
}

export interface StockBreakdownView {
    readonly available: number;
    readonly reserved: number;
    readonly shortfall: number;
}

export interface ProductDefaults {
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly skuSequence?: number;
    readonly maxImages?: number;
    readonly fewLeft?: number;
}

export interface AiAvailability {
    readonly available: boolean;
    readonly remaining: number | null;
}

export type CopywritingMode = 'suggest' | 'improve';

export interface CopySuggestion {
    readonly description: string;
    readonly characteristics: readonly string[];
    readonly tags?: readonly string[];
    readonly remaining: number;
}

export interface ProductListItem {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly status: ProductStatus;
    readonly stock: number;
    readonly reservedUnits: number;
    readonly shortfallUnits: number;
    readonly price: MoneyView;
    readonly categoryName: string | null;
    readonly catalogsCount: number;
    readonly scheduledFor: string | null;
    readonly thumbUrl?: string | null;
    readonly plan?: { readonly downPayment: MoneyView; readonly installments: number; readonly frequencyDays: number };
    readonly updatedAt: string | null;
    readonly updatedBy: string | null;
}

export interface ProductFilters {
    readonly status: string | null;
    readonly category: string | null;
    readonly tag: string | null;
    readonly low_stock: string | null;
    readonly q: string | null;
    readonly scheduled?: string | null;
}
