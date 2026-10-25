import { type CursorPage, type ProductCard } from '@/types/catalog-view';

export type AccountProvider = 'google';

export interface GoogleSignInExpiredProps {
    readonly retryUrl: string;
    readonly backUrl: string;
}

export interface FollowState {
    readonly signedIn: boolean;
    readonly following: boolean;
    readonly url: string;
}

export interface SubscriptionRow {
    readonly catalogId: number;
    readonly catalogSlug: string;
    readonly catalogName: string;
    readonly businessName: string;
    readonly active: boolean;
    readonly muted: boolean;
    readonly productsCount: number;
    readonly newCount: number;
    readonly subscribedAt: string;
    readonly url: string | null;
}

export interface SubscriptionsPageProps {
    readonly account: { readonly displayName: string };
    readonly subscriptions: readonly SubscriptionRow[];
}

export interface LayawayFollowNotice {
    readonly unfollowUrl: string;
}

export interface FeedItem {
    readonly product: ProductCard;
    readonly businessName: string;
    readonly catalogName: string;
    readonly catalogUrl?: string;
    readonly isNew: boolean;
    readonly enteredAt: string;
    readonly url: string;
}

export interface FeedFiltersView {
    readonly catalog: string | null;
    readonly q: string | null;
}

export interface FeedPageProps {
    readonly items: CursorPage<FeedItem>;
    readonly subscriptions: readonly SubscriptionRow[];
    readonly filters: FeedFiltersView;
}

export interface FeedPreview {
    readonly items: readonly FeedItem[];
    readonly url: string;
}
