export type TelemetryEventName =
    | 'catalog_viewed'
    | 'product_viewed'
    | 'product_list_filtered'
    | 'search_performed'
    | 'layaway_form_started'
    | 'layaway_created'
    | 'receipt_uploaded'
    | 'currency_toggled'
    | 'dwell_time_recorded'
    | 'cta_clicked'
    | 'account_created'
    | 'catalog_subscribed'
    | 'catalog_unsubscribed'
    | 'feed_viewed'
    | 'feed_product_clicked';

export const ESSENTIAL_EVENTS: ReadonlySet<TelemetryEventName> = new Set<TelemetryEventName>([
    'layaway_created',
    'receipt_uploaded',
    'account_created',
    'catalog_subscribed',
    'catalog_unsubscribed',
]);

export type TelemetryPayload = Readonly<Record<string, string | number | readonly string[]>>;

export interface TelemetryEvent {
    readonly name: TelemetryEventName;
    readonly occurredAt: string;
    readonly catalogSlug?: string;
    readonly subjectSlug?: string;
    readonly payload?: TelemetryPayload;
}

export interface TrackOptions {
    readonly catalogSlug?: string;
    readonly subjectSlug?: string;
    readonly payload?: TelemetryPayload;
}
