import { type FollowState } from '@/types/account';
import { type OrganizationBrand } from '@/types/backoffice';
import { type CatalogChatState } from '@/types/chat';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';
import { type ImageSet } from '@/types/product';

export interface CatalogView {
    readonly slug: string;
    readonly name: string;
    readonly description: string | null;
    readonly cover: ImageSet | null;
    readonly business: OrganizationBrand;
    readonly shareUrl: string;
    readonly expiresAt: string | null;
    readonly showsPlatformBadge: boolean;
}

export interface ProductCard {
    readonly slug: string;
    readonly name: string;
    readonly price: MoneyView;
    readonly compareAtPrice: MoneyView | null;
    readonly downPayment: MoneyView;
    readonly installmentAmount: MoneyView;
    readonly lastInstallmentAmount: MoneyView | null;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly image: ImageSet | null;
    readonly categoryName: string;
    readonly tags: readonly string[];
    readonly inStock: boolean;
    readonly catalogSlug: string;
    readonly isNew?: boolean;
    readonly savingsPercent?: number | null;
}

export interface ProductDetail extends Omit<ProductCard, 'image' | 'installmentAmount'> {
    readonly installmentAmount: MoneyView | null;
    readonly description: string;
    readonly sku: string;
    readonly images: readonly ImageSet[];
    readonly specifications: readonly string[];
    readonly maxQuantity: number;
    readonly paymentAccounts: readonly PaymentAccountView[];
    readonly canBeReserved: boolean;
    readonly unavailableReason: string | null;
    readonly stockLeft?: number | null;
}

export interface ProductShowProps {
    readonly catalog: CatalogView;
    readonly product: ProductDetail;
    readonly related: readonly ProductCard[];
}

export interface CursorPage<T> {
    readonly data: readonly T[];
    readonly nextCursor: string | null;
}

export type CatalogSort = 'curated' | 'recent' | 'price_asc' | 'price_desc' | 'name';

export interface CatalogFilters {
    readonly q: string | null;
    readonly category: string | null;
    readonly tags: readonly string[];
    readonly sort: CatalogSort | null;
    readonly ignored: boolean;
}

export interface Facet {
    readonly slug: string;
    readonly name: string;
    readonly count: number;
}

export interface CatalogFacets {
    readonly categories: readonly Facet[];
    readonly tags: readonly Facet[];
}

export interface CatalogShowProps {
    readonly catalog: CatalogView;
    readonly products: CursorPage<ProductCard>;
    readonly filters: CatalogFilters;
    readonly facets: CatalogFacets;
    readonly follow: FollowState;
    readonly chat: CatalogChatState;
    readonly stats?: { readonly products: number; readonly followers: number };
}

export interface CatalogExpiredProps {
    readonly catalogName: string;
    readonly businessName: string;
    readonly storefrontUrl: string | null;
    readonly endedOn?: string | null;
    readonly withdrawn?: boolean;
}

export interface StorefrontCatalog {
    readonly slug: string;
    readonly name: string;
    readonly description: string | null;
    readonly cover: ImageSet | null;
    readonly url: string;
    readonly expiresAt: string | null;
    readonly productsCount?: number;
    readonly thumbs?: readonly string[];
}

export interface OrganizationStorefrontProps {
    readonly business: OrganizationBrand;
    readonly showsPlatformBadge: boolean;
    readonly catalogs: readonly StorefrontCatalog[];
    readonly profile?: { readonly description: string | null; readonly address: string | null; readonly phone: string | null };
}
