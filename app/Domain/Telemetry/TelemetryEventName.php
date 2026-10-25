<?php

declare(strict_types=1);

namespace App\Domain\Telemetry;

enum TelemetryEventName: string
{
    case CatalogViewed = 'catalog_viewed';
    case ProductViewed = 'product_viewed';
    case ProductListFiltered = 'product_list_filtered';
    case SearchPerformed = 'search_performed';
    case LayawayFormStarted = 'layaway_form_started';
    case LayawayCreated = 'layaway_created';
    case ReceiptUploaded = 'receipt_uploaded';
    case CurrencyToggled = 'currency_toggled';
    case DwellTimeRecorded = 'dwell_time_recorded';
    case CtaClicked = 'cta_clicked';
    case AccountCreated = 'account_created';
    case CatalogSubscribed = 'catalog_subscribed';
    case CatalogUnsubscribed = 'catalog_unsubscribed';
    case FeedViewed = 'feed_viewed';
    case FeedProductClicked = 'feed_product_clicked';

    /**
     * @return array<string, 'string'|'int'|'list'>
     */
    public function payloadRules(): array
    {
        return match ($this) {
            self::CatalogViewed, self::ProductViewed, self::LayawayFormStarted, self::CatalogSubscribed => ['source' => 'string'],
            self::ProductListFiltered => ['category' => 'string', 'tags' => 'list', 'sort' => 'string', 'results' => 'int'],
            self::SearchPerformed => ['term' => 'string', 'results' => 'int'],
            self::LayawayCreated => ['product_id' => 'int', 'quantity' => 'int', 'source' => 'string'],
            self::ReceiptUploaded => ['method' => 'string'],
            self::CurrencyToggled => ['to' => 'string'],
            self::DwellTimeRecorded => ['path' => 'string', 'seconds' => 'int'],
            self::CtaClicked => ['cta' => 'string', 'path' => 'string'],
            self::AccountCreated => ['provider' => 'string'],
            self::CatalogUnsubscribed => [],
            self::FeedViewed => ['subscriptions_count' => 'int', 'items' => 'int'],
            self::FeedProductClicked => ['position' => 'int'],
        };
    }

    public function subjectType(): ?string
    {
        return match ($this) {
            self::CatalogViewed, self::CatalogSubscribed, self::CatalogUnsubscribed => 'catalog',
            self::ProductViewed, self::LayawayFormStarted, self::FeedProductClicked, self::DwellTimeRecorded => 'product',
            self::LayawayCreated, self::ReceiptUploaded => 'layaway',
            default => null,
        };
    }

    public function requiresCatalog(): bool
    {
        return in_array($this, [
            self::CatalogViewed, self::ProductViewed, self::ProductListFiltered, self::SearchPerformed,
            self::LayawayFormStarted, self::LayawayCreated, self::ReceiptUploaded, self::DwellTimeRecorded,
        ], true);
    }

    public function isEssential(): bool
    {
        return in_array($this, [self::LayawayCreated, self::ReceiptUploaded, self::AccountCreated, self::CatalogSubscribed, self::CatalogUnsubscribed], true);
    }
}
