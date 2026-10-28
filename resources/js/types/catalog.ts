import { type MoneyView } from '@/types/money';
import { type ProductStatus } from '@/types/product';

export interface CatalogProductOption {
    readonly id: number;
    readonly name: string;
    readonly sku: string;
    readonly status: ProductStatus;
    readonly price: MoneyView;
    readonly categoryName: string | null;
    readonly thumbUrl?: string | null;
}

export type CatalogVisibility = 'draft' | 'listed' | 'unlisted' | 'expired';

export interface CatalogListItem {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly visibility: CatalogVisibility;
    readonly isListed: boolean;
    readonly publishedAt: string | null;
    readonly expiresAt: string | null;
    readonly shareUrl: string;
    readonly slugIsLocked: boolean;
    readonly productsCount: number;
    readonly subscribersCount?: number;
    readonly coverUrl?: string | null;
    readonly thumbs?: readonly string[];
    readonly updatedAt: string | null;
    readonly updatedBy: string | null;
}

export interface CatalogFormValues extends CatalogListItem {
    readonly description: string | null;
    readonly coverUrl: string | null;
}

export type CatalogPublishBlockers = Readonly<Record<string, string>>;
