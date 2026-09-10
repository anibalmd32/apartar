# Apartar — Requirements (alignment mirror)

> **GENERATED ARTIFACT — DO NOT EDIT BY HAND.**
> Source of truth: the Spanish modules in `docs/specs/`. Correct the module first, then propagate here.
> Purpose: `indexion spec align` matches specification vocabulary against code identifiers. The Spanish
> modules would produce false `DRIFTED` results against an English codebase, so the tooling consumes
> this mirror only. See `REQ-QAX-005` in `docs/specs/91-calidad-y-gates.md`.
>
> Each item lists normative `shall` statements plus the **expected identifiers** that must appear in the
> implementation. **118 requirements**, IDs identical to the Spanish modules (1:1 traceability).
>
> Product shape: multi-tenant SaaS. A business (`Organization`) publishes shareable catalogs with a
> layaway (installment reservation) system and collects from its own customers. Monetized by subscription
> with a free tier. Two distinct end-user identities: `Customer` (commercial, per organization, keyed by
> national ID) and `Account` (global consumer, keyed by Google).

---

### Requirement 1: Tenancy, organizations and team

#### 1.1: REQ-ORG-001 — Self-serve organization registration
The system shall create an organization, an owner user, a free subscription and default settings in a single
transaction, shall enforce a globally unique organization slug and a globally unique team email, and shall
require owner email verification before a catalog may be published.
Expected identifiers: `Organization`, `OrganizationStatus`, `OrganizationRole`, `RegisterOrganizationData`, `RegisterOrganizationAction`, `SlugTakenException`.

#### 1.2: REQ-ORG-002 — Tenant resolution and data isolation
The system shall never execute a query against a tenant table without filtering by `organization_id`, shall
apply that filter through a global scope and fill the column on create, shall include `organization_id` in
every business unique index except three declared exceptions, and shall resolve the tenant from the session,
the catalog, the portal context or an explicit job parameter.
Expected identifiers: `TenantContext`, `BelongsToOrganization`, `TenantResolver`, `ResolveTenant`, `ResolveCatalogTenant`, `ResolvePortalTenant`, `MissingTenantContextException`, `CrossTenantRelationException`.

#### 1.3: REQ-ORG-003 — Organization roles
The system shall support exactly two roles, owner and member, shall restrict billing, team and settings
management to owners, shall always keep at least one active owner under a transactional check, and shall
resolve the role from the database on every request rather than from the session.
Expected identifiers: `OrganizationRole`, `LastOwnerException`, `OrganizationPolicy`, `UserPolicy`, `EnsureIsOwner`.

#### 1.4: REQ-ORG-004 — Team member invitation
The system shall allow only an owner to invite team members, shall count a pending invitation against the
plan user limit, shall issue a single-use 48-hour invitation token, and shall release the limit unit when an
invitation is revoked.
Expected identifiers: `InviteTeamMemberAction`, `TeamInvitation`, `TeamMember`, `PlanLimitReachedException`, `AcceptInvitationController`.

#### 1.5: REQ-ORG-005 — Logical deactivation of team members
The system shall never physically delete a team member, shall invalidate a deactivated member's session on
the next request, shall prevent self-deactivation and the deactivation of the last active owner, and shall
release the plan user unit on deactivation.
Expected identifiers: `DisableTeamMemberAction`, `EnableTeamMemberAction`, `LastOwnerException`, `users.disabled_at`.

#### 1.6: REQ-ORG-006 — Organization settings, identity and storefront
The system shall expose per-organization settings validated per key and cached per organization, shall apply
branding only when the plan allows it, shall serve an optional organization storefront listing only that
organization's listed catalogs, and shall reject reserved slugs.
Expected identifiers: `SettingKey`, `Settings`, `UpdateOrganizationAction`, `UpdateSettingsAction`, `OrganizationBrand`, `ReservedSlugs`, `OrganizationStorefrontController`.

#### 1.7: REQ-ORG-007 — Organization payment accounts
The system shall let an organization declare where it receives payments, shall require at least one active
payment account before a catalog may be published, shall validate the identifier per payment method, and
shall deactivate rather than delete an account that has receipts.
Expected identifiers: `PaymentAccount`, `PaymentAccountView`, `UpsertPaymentAccountAction`, `ValidPaymentIdentifier`, `PaymentAccountInUseException`.

#### 1.8: REQ-ORG-008 — Suspension, closure and data retention
The system shall, on suspension, unpublish catalogs and restrict the team to reading collections and
approving receipts, and shall never interrupt an active layaway, the customer portal, receipt submission or
the customer's access to their history. Closure shall require no active layaways and shall preserve data.
Expected identifiers: `OrganizationStatus`, `SuspendOrganizationAction`, `CloseOrganizationAction`, `EnsureOrganizationIsActive`, `OrganizationHasActiveLayawaysException`.

---

### Requirement 2: Customer identity and authentication

#### 2.1: REQ-IDN-001 — Per-organization customer record
The system shall identify a customer by `(organization_id, document_number)`, shall require a phone number,
shall treat email as optional and non-unique, and shall create the record inside the layaway transaction.
Expected identifiers: `Customer`, `CustomerContactData`, `DocumentNumber`, `DocumentKind`, `PhoneNumber`, `ResolveCustomerAction`, `InvalidDocumentNumberException`.

#### 2.2: REQ-IDN-002 — Interaction between commercial record and consumer account
The system shall pre-fill and lock the email from an active account session, shall ignore any submitted
email in that case, shall never take the document number or phone from the account, and shall link the
record to the account on layaway creation without overwriting an existing link.
Expected identifiers: `LinkOrigin`, `LinkAccountAction`, `CustomerIdentity`, `AccountSession`.

#### 2.3: REQ-IDN-003 — Email magic link as a secondary path
The system shall issue a single-use 30-minute login token per organization when document and email match a
record, shall store only its hash, shall respond identically when they do not match, and shall consume the
token only on an explicit POST.
Expected identifiers: `CustomerLoginToken`, `IssueLoginLinkAction`, `ConsumeLoginTokenAction`, `ExpiredLoginTokenException`, `ConsumedLoginTokenException`.

#### 2.4: REQ-IDN-004 — Scoped customer session and portal guards
The system shall provide four mutually independent guards (`account`, `customer`, `web`, `platform`), shall
scope a customer session to one organization, shall never run an unfiltered query for an account session,
and shall preserve the telemetry visitor id across logout.
Expected identifiers: guard `customer`, guard `account`, `EnsurePortalSession`, `ResolvePortalTenant`, `PortalContext`, `PortalSessionView`.

#### 2.5: REQ-IDN-005 — Terms and privacy consent
The system shall require accepted terms on the layaway form, shall persist the acceptance timestamp and IP
as evidence, and shall link to both the platform terms and the business's layaway conditions.
Expected identifiers: `StoreLayawayRequest`, `customers.terms_accepted_at`, `customers.terms_ip_address`, `TermsConsentField`, `TrustProxies`.

#### 2.6: REQ-IDN-006 — Manual merge of duplicate customer records
The system shall merge two customer records only within the same organization by explicit action, shall
transfer layaways, receipts, payments, login tokens, messages and telemetry events, and shall refuse the
merge when both records are linked to different consumer accounts.
Expected identifiers: `MergeCustomersAction`, `MergeSummary`, `CannotMergeCustomerException`.

#### 2.7: REQ-IDN-007 — Document and phone normalization
The system shall normalize national IDs and tax IDs to a canonical form, shall require an explicit prefix
for a tax ID, shall preserve leading zeros, shall normalize phones to E.164, and shall apply the same
normalization to backoffice search.
Expected identifiers: `DocumentNumber`, `DocumentKind`, `PhoneNumber`, `ValidDocumentNumber`, `ValidVenezuelanPhone`, `InvalidPhoneNumberException`.

#### 2.8: REQ-IDN-008 — Access by document number and layaway code
The system shall open a scoped portal session when a document number and a layaway code both resolve to the
same customer record, shall never accept the document number alone, shall rate limit attempts and respond
identically on failure, and shall work for terminal layaways and suspended organizations.
Expected identifiers: `StartScopedSessionAction`, `ScopedSessionResult`, `LayawayCode`, `AccessVerificationFailedException`.

---

### Requirement 3: Products, taxonomy and technical specifications

#### 3.1: REQ-CAT-002 — Product search within a catalog
The system shall search products scoped to the catalog being viewed using a MySQL full-text index, shall
escape boolean operators, shall degrade to a LIKE match for short terms, and shall emit a search event with
the catalog id.
Expected identifiers: `scopeSearch`, `scopeInCatalog`, `FullTextTerm`, `CatalogFilters`.

#### 3.2: REQ-CAT-003 — Product detail page inside a catalog
The system shall resolve a product only within its catalog context, shall present the financing breakdown
using the same domain calculation as the real schedule, shall display the organization's active payment
accounts, and shall disable reservation with an explicit reason that never reveals the business's plan.
Expected identifiers: `ProductDetail`, `ProductDetailResource`, `ProductShowController`, `PaymentAccountView`, `OrganizationBrand`, `ScheduleGenerator`.

#### 3.3: REQ-CAT-004 — Dynamic technical specification display
The system shall store specifications relationally with an ordering position and an optional group, shall
render ungrouped rows first, and shall escape all label and value content as plain text.
Expected identifiers: `ProductSpecification`, `SpecificationGroup`, `SpecificationTable`.

#### 3.4: REQ-CAT-005 — Product image gallery and queued conversions
The system shall generate WebP conversions in a queued job, shall serve the original until conversions
exist, shall promote the next image when the primary is deleted, shall store files under a path containing
the organization id, and shall count image weight against the plan storage quota.
Expected identifiers: `registerMediaConversions`, `TranscodeProductImage`, `ImageSet`, `ProductGallery`, `StorageQuotaExceededException`.

#### 3.5: REQ-CAT-006 — Per-organization taxonomy and catalog filters
The system shall keep categories and tags per organization, shall offer as catalog filters only the facets
present in that catalog with their counts, shall apply OR semantics to tags, and shall expose no category or
tag page outside a catalog.
Expected identifiers: `Category`, `Tag`, `CatalogFilters`, `CatalogFacetsQuery`, `useCatalogFilters`.

#### 3.6: REQ-CAT-007 — Publication status, availability and safe deletion
The system shall support draft, published and archived statuses with soft deletes, shall treat visibility as
the intersection of product status and catalog accessibility, shall keep zero-stock products visible and
marked out of stock, shall preserve catalog relations when archiving, and shall count only non-archived
products against the plan limit.
Expected identifiers: `ProductStatus`, `ChangeProductStatusAction`, `ProductNotAvailableException`, `scopePublished`.

---

### Requirement 4: The catalog as the storefront

#### 4.1: REQ-CAT-021 — Public catalog view
The system shall make the catalog the only end-customer discovery surface, shall serve listed and unlisted
catalogs without authentication, shall derive the tenant and all context from the catalog, shall offer
search, filters, sorting and cursor pagination within it, shall expose a follow action, and shall return 404
for a draft catalog and 410 for an expired one.
Expected identifiers: `Catalog`, `CatalogVisibility`, `CatalogView`, `CatalogShowController`, `ResolveCatalogTenant`, `CatalogViewResource`, `CatalogExpiredException`.

#### 4.2: REQ-CAT-022 — Publication, visibility, link and expiry
The system shall keep the catalog slug globally unique and immutable after first publication, shall treat
the listed flag as listing-only and never as access control, shall verify six publication preconditions
including an active payment account and plan quota, and shall display a platform badge unless the plan
includes branding.
Expected identifiers: `PublishCatalogAction`, `UnpublishCatalogAction`, `PublishCatalogData`, `UniqueCatalogSlug`, `SlugLockedException`, `CatalogNotPublishableException`.

#### 4.3: REQ-CAT-023 — Curated composition and ordering
The system shall relate products and catalogs many-to-many within one organization with a contiguous
position starting at 1, shall preserve the pivot creation timestamp when reordering, shall deduplicate
submitted identifiers, and shall enforce the per-catalog product limit of the plan.
Expected identifiers: `SyncCatalogProductsAction`, `catalog_product.position`, `catalog_product.created_at`, `CatalogProductOrder`, `CrossTenantRelationException`.

---

### Requirement 5: Dual currency, money and exchange rate

#### 5.1: REQ-FX-001 — Money value object and cent-based storage
The system shall store every monetary amount as `BIGINT` USD cents, shall forbid floating point for money,
and shall guarantee that `allocateEvenly` sums exactly to the original amount with the remainder in the last
part.
Expected identifiers: `Money`, `Currency`, `MoneyFormatter`, `MoneyCast`, `CurrencyMismatchException`, `InvalidRateException`, `CentsUsd`, `MoneyView`.

#### 5.2: REQ-FX-002 — Scheduled rate ingestion and immutable history
The system shall ingest the exchange rate once for the whole platform every 30 minutes into a
platform-level table, shall append-only persist it, shall cache the current rate per source, and shall
reject implausible rates.
Expected identifiers: `ExchangeRate`, `ExchangeRateService`, `FetchExchangeRate`, `RateSnapshot`, `RateSource`, `ExchangeRateUnavailableException`.

#### 5.3: REQ-FX-003 — Pluggable provider and per-organization rate source
The system shall let each organization choose its rate source among BCV, parallel and manual, shall resolve
the provider from configuration behind an interface, shall keep a manual rate in the organization's own
settings, and shall always convert subscription invoices with the platform's source rather than the
organization's.
Expected identifiers: `ExchangeRateProvider`, `PyDolarVeProvider`, `ManualRateProvider`, `ExchangeRateService`, `UpdateExchangeRateSettingsAction`.

#### 5.4: REQ-FX-004 — Dual currency projection and display toggle
The system shall project every amount in both currencies, shall support two display modes consistently
across catalog, portal and feed, shall persist the preference client-side as a property of the person rather
than the organization, and shall require no server request to switch.
Expected identifiers: `CurrencyDisplay`, `MoneyView`, `useCurrencyDisplay`, `MoneyLabel`, `CurrencyToggle`, `ProjectsMoney`.

#### 5.5: REQ-FX-005 — Graceful degradation when the rate is unavailable
The system shall never block browsing or layaway creation when the rate is unavailable, shall stamp the rate
age, shall omit the secondary currency entirely when no rate exists, and shall surface a stale rate as a
platform-level alert.
Expected identifiers: `RateView`, `RateStamp`, `MissingExchangeRateException`, `RateSnapshot::isStale`.

#### 5.6: REQ-FX-006 — Immutable rate snapshot on financial operations
The system shall denominate debt exclusively in USD, shall convert a secondary-currency payment using the
rate in force on settlement day, and shall freeze the applied rate, its source and its fetch time immutably
on the payment.
Expected identifiers: `Payment.rate_applied`, `Payment.rate_source`, `Payment.rate_fetched_at`, `ApproveReceiptAction`, `RegisterManualPaymentAction`.

---

### Requirement 6: Consumer accounts, catalog subscriptions and feed

#### 6.1: REQ-ACC-001 — Consumer account with Google
The system shall create a global consumer account keyed by the identity provider subject and never by
email, shall require a verified email from the provider, shall store no document number or phone on the
account, and shall keep the account identity independent from team and platform identities.
Expected identifiers: `Account`, `AccountIdentity`, `AccountProvider`, `AuthenticateAccountAction`, `AccountSession`, `GoogleUserData`.

#### 6.2: REQ-ACC-002 — Catalog subscription
The system shall require an account session to follow a catalog, shall never require an account to browse or
to create a layaway, shall make subscription idempotent, shall auto-subscribe on layaway creation when an
account session exists, shall support muting, and shall impose no subscriber limit in any plan.
Expected identifiers: `CatalogSubscription`, `SubscriptionRow`, `SubscribeToCatalogAction`, `UnsubscribeFromCatalogAction`, `CatalogNotSubscribableException`, `FollowCatalogButton`.

#### 6.3: REQ-ACC-003 — Feed of followed catalogs
The system shall build the feed exclusively from published products of catalogs the account follows, shall
order it chronologically by catalog inclusion, shall deduplicate a product within one organization, shall
filter only by catalog and text, shall paginate by cursor, and shall require an account session.
Expected identifiers: `FeedQuery`, `FeedItem`, `FeedFilters`, `FeedController`, `FeedItemResource`, `FeedList`.

#### 6.4: REQ-ACC-004 — Linking accounts to commercial records
The system shall link an account to a customer record only on layaway creation or through a verified claim
requiring both document number and layaway code, shall never link by email or by document number alone,
shall allow at most one record per organization per account, and shall audit the link in the organization.
Expected identifiers: `LinkOrigin`, `ClaimCustomerAction`, `LinkAccountAction`, `CustomerAlreadyLinkedException`, `ClaimVerificationFailedException`.

#### 6.5: REQ-ACC-005 — The two portal session modes
The system shall support an account session that sees every linked record grouped by business and a scoped
session limited to one organization, shall expose feed and subscriptions only to the account session, shall
resolve visible records exclusively through the portal context, and shall prefer the account session when
both exist.
Expected identifiers: `PortalContext`, `EnsurePortalSession`, `AccountSession`, `useAccountSession`.

#### 6.6: REQ-ACC-006 — New-product digest to subscribers
The system shall send at most one daily digest per catalog per account, guaranteed by a unique database
constraint, shall group multiple new products into one message, shall exclude muted subscriptions, shall
send in the organization's local time zone, and shall require the subscriber-digest plan feature.
Expected identifiers: `CatalogDigestDispatch`, `DispatchCatalogDigestAction`, `DispatchCatalogDigests`, `CatalogDigestNotification`, `PlanFeature::SubscriberDigest`.

#### 6.7: REQ-ACC-007 — Subscriber privacy toward the organization
The system shall expose to an organization only a subscriber's public display name, avatar, subscription
date and catalog, never their email or phone, shall allow blind broadcast without contact access, shall
never reveal that a subscriber or customer also deals with another organization, shall never expose the
account id in any response or export, and shall not offer subscriber export.
Expected identifiers: `SubscriberRow`, `SubscriberListQuery`, `SubscriberStatsQuery`, `SubscriberRowResource`.

---

### Requirement 7: Layaway, state machine and amortization schedule

#### 7.1: REQ-LAY-001 — Layaway creation from the catalog
The system shall create a layaway inside a catalog context from which the organization is derived, shall
require no registration, shall require document number and phone, shall verify catalog accessibility,
product membership, stock, plan quota and organization status, and shall be idempotent per client key.
Expected identifiers: `Layaway`, `LayawayStatus`, `CreateLayawayAction`, `CreateLayawayData`, `LayawayTerms`, `LayawayCode`, `StoreLayawayRequest`, `InsufficientStockException`, `PlanLimitReachedException`.

#### 7.2: REQ-LAY-002 — Immutable snapshot of commercial terms
The system shall copy unit price, down payment, installment count, frequency, product name and SKU onto the
layaway at creation, and later changes to the product, the catalog or the organization settings shall never
affect existing layaways.
Expected identifiers: `LayawayTerms`, `layaways.unit_price_cents`, `layaways.product_name_snapshot`, `Layaway::terms`.

#### 7.3: REQ-LAY-003 — Stock reservation and release
The system shall decrement stock at layaway creation under a pessimistic row lock, shall never allow
negative stock, shall release stock idempotently on expiry or cancellation but not on completion, and shall
follow a canonical lock order.
Expected identifiers: `ReserveStockAction`, `ReleaseStockAction`, `products.stock`, `layaways.stock_released_at`, `InsufficientStockException`.

#### 7.4: REQ-LAY-004 — Amortization schedule generation
The system shall materialize installments on activation, shall distribute the financed amount evenly with
the remainder in the last installment, and shall compute each due date in the organization's time zone.
Expected identifiers: `ScheduleGenerator`, `AmortizationSchedule`, `ScheduledInstallment`, `Installment`, `InstallmentStatus`, `InvalidScheduleException`.

#### 7.5: REQ-LAY-005 — Layaway state machine
The system shall restrict transitions to the declared matrix, shall write every state change through the
state machine only, shall adjust the plan's active-layaway usage on every transition, shall dispatch domain
events after commit, and shall keep working while the organization is suspended.
Expected identifiers: `LayawayStatus`, `LayawayStateMachine`, `IllegalLayawayTransitionException`, `allowedTransitions`, `acceptsPayments`, `countsTowardPlanLimit`.

#### 7.6: REQ-LAY-006 — Expiry for a missing down payment
The system shall expire a layaway with no receipt after the organization's configured window, shall freeze
that window while a receipt is under review, shall grant a single 24-hour extension after a late rejection,
and shall release both stock and plan quota.
Expected identifiers: `ExpireLayawaysAction`, `ExpireLayawaysJob`, `layaways.expires_at`, `layaways.extended_at`.

#### 7.7: REQ-LAY-007 — Overdue detection and grace period
The system shall mark an installment overdue only after the organization's grace period evaluated in its own
time zone, shall mark the layaway overdue when any installment is overdue, and shall apply no interest or
penalty.
Expected identifiers: `DetectOverdueAction`, `DetectOverdueLayawaysJob`, `InstallmentStatus`, `scopeOverdue`.

#### 7.8: REQ-LAY-008 — Early settlement
The system shall allow settling the full outstanding balance at any time without discount, and shall
complete the layaway and release plan quota when the remaining balance falls at or below the organization's
settlement tolerance.
Expected identifiers: `FifoAllocator`, `AllocationPlan`, `layaways.remaining_cents`.

#### 7.9: REQ-LAY-009 — Cancellation by the business
The system shall cancel a layaway only by explicit action of a team member with a mandatory reason, shall
release stock and plan quota, shall auto-reject pending receipts, shall compute no refund, and shall never
expose the internal reason to the customer.
Expected identifiers: `CancelLayawayAction`, `CancellationData`, `layaways.cancellation_reason`.

#### 7.10: REQ-LAY-010 — Product delivery
The system shall allow marking delivery only for a completed layaway, shall be idempotent, and shall not
treat delivery as a state transition or a change in plan usage.
Expected identifiers: `MarkDeliveredAction`, `layaways.delivered_at`, `scopeCompletedNotDelivered`.

#### 7.11: REQ-LAY-011 — Installment rescheduling
The system shall allow changing only the due date of an unpaid installment, shall keep the amount immutable,
shall preserve schedule monotonicity, and shall require a reason with audit.
Expected identifiers: `RescheduleInstallmentAction`, `InvalidRescheduleException`, `AmortizationSchedule::validMoveWindow`.

---

### Requirement 8: Customer portal

#### 8.1: REQ-LAY-020 — Portal home: outstanding debt and new arrivals
The system shall group layaways by business and never mix two businesses in one list, shall aggregate totals
across businesses only in account mode, shall order business blocks by nearest due date, shall combine debt
and feed preview on the home screen, and shall issue one scoped query per organization.
Expected identifiers: `PortalDashboard`, `BusinessBlock`, `PortalTotals`, `LayawaySummary`, `CustomerBalanceQuery`.

#### 8.2: REQ-LAY-021 — Layaway detail and schedule
The system shall resolve a layaway by public code scoped to the portal context, shall identify the business,
shall list every installment with days until due computed in the organization's time zone, shall show
payment instructions and payment accounts while the down payment is pending, and shall never expose the
cancellation reason to the customer.
Expected identifiers: `InstallmentRow`, `InstallmentRowResource`, `LayawayShowController`, `ScheduleTable`.

#### 8.3: REQ-LAY-022 — Payment and receipt history
The system shall display each payment with its frozen rate and source and the installments it amortized,
shall never reconvert historical amounts, shall expose the receipt rejection reason in full, shall serve
receipt files through short-lived signed URLs, and shall label payments registered by the business.
Expected identifiers: `PaymentRow`, `ReceiptRow`, `PaymentRowResource`, `SignedFileUrl`, `ReceiptPolicy`.

#### 8.4: REQ-LAY-023 — Portal alerts
The system shall compute portal alerts server-side with the same thresholds as the reminder schedule, shall
identify the business on each alert, shall consolidate overdue alerts across businesses, and shall never
reveal whether an unlinked customer record exists for a document number.
Expected identifiers: `PortalAlert`, `PortalAlertsQuery`, `PortalAlertResource`, `PortalAlerts`.

---

### Requirement 9: Payment receipts, payments and balances

#### 9.1: REQ-PAY-001 — Customer receipt submission
The system shall accept a receipt only from the record holder resolved through the portal context, shall
record which payment account the customer paid to, shall create it pending review without touching the
balance, and shall never reject a customer receipt because of the organization's storage quota.
Expected identifiers: `Receipt`, `ReceiptStatus`, `PaymentMethod`, `SubmitReceiptData`, `SubmitReceiptAction`, `DuplicateReceiptException`.

#### 9.2: REQ-PAY-002 — Private storage and signed access
The system shall store receipt files on a private disk under a path containing the organization id, shall
require both a valid signature and policy authorization plus tenant membership, shall validate the real MIME
type, and shall never delete a receipt file.
Expected identifiers: `SignedFileUrl`, `ReceiptFileController`, `ReceiptPolicy`, `RealMimeType`, `CompressReceiptImage`.

#### 9.3: REQ-PAY-003 — Duplicate receipt detection
The system shall hard-block a receipt whose file hash or whose bank and reference pair already exists
**within the same organization**, shall permit the same reference in a different organization, and shall not
release a reference when a receipt is rejected.
Expected identifiers: `DuplicateReceiptException`, `UniqueReceiptFingerprint`, `DuplicateCriterion`, `receipts.file_hash`.

#### 9.4: REQ-PAY-004 — Reconciliation inbox with real-time alerts
The system shall broadcast new receipts on a per-organization private channel authorized only for members of
that organization, shall order pending receipts oldest first, shall flag receipts pending more than 48
hours, and shall keep working while the organization is suspended.
Expected identifiers: `ReceiptSubmitted`, `ReceiptInboxController`, `ReceiptInboxRow`, `useReceiptInbox`.

#### 9.5: REQ-PAY-005 — Receipt approval and FIFO amortization
The system shall approve a receipt in one transaction that creates the payment with its rate snapshot,
allocates it FIFO across installments, updates the materialized balance, transitions the layaway and adjusts
plan usage, and shall let the reviewer adjust the settled amount and the applied rate.
Expected identifiers: `ApproveReceiptData`, `ApproveReceiptAction`, `PaymentApplier`, `FifoAllocator`, `AllocationPlan`, `Payment`, `PaymentAllocation`, `ReceiptAlreadyProcessedException`, `MissingExchangeRateException`.

#### 9.6: REQ-PAY-006 — Receipt rejection and retry
The system shall require a reason code and free text on rejection, shall preserve the file, shall notify the
customer with the full reason, shall allow a linked replacement receipt, and shall grant the down-payment
extension when the window had already lapsed.
Expected identifiers: `RejectReceiptAction`, `RejectionReasonCode`, `receipts.replaces_receipt_id`, `ReceiptRejectedNotification`.

#### 9.7: REQ-PAY-007 — Manual payment registration by the business
The system shall allow registering a payment without a receipt with a mandatory note and the registering
user recorded, reusing the same payment applier as receipt approval so both paths produce identical results.
Expected identifiers: `ManualPaymentData`, `RegisterManualPaymentAction`, `PaymentApplier`, `payments.registered_by`.

#### 9.8: REQ-PAY-008 — Materialized balance and reconciliation
The system shall maintain paid, remaining and next due date materialized on the layaway, recalculated inside
every payment transaction by a single collaborator, and shall provide an idempotent multi-tenant
reconciliation command that reports and optionally fixes discrepancies.
Expected identifiers: `LayawayBalanceRecalculator`, `ReconcileLayaways`, `ReconciliationReport`, `BalanceDiscrepancy`.

#### 9.9: REQ-PAY-009 — Manual payment reallocation
The system shall allow reallocating a payment across installments of the same layaway while preserving the
total allocated amount, shall never modify the payment amount, currency or rate, and shall recalculate state
and plan usage afterwards.
Expected identifiers: `ReallocatePaymentAction`, `AllocationPlan`, `InvalidReallocationException`.

---

### Requirement 10: Team access and organization audit

#### 10.1: REQ-BOF-001 — Protected access to the organization backoffice
The system shall authenticate team members through the web guard without asking for the organization, shall
derive the tenant from the authenticated user, shall rate limit login attempts, shall invalidate sessions of
disabled members, shall restrict a suspended organization to reading collections and approving receipts, and
shall offer self-serve password recovery.
Expected identifiers: `User`, `AuthenticatedTeamMember`, `LoginController`, `EnsureAdminIsActive`, `EnsureOrganizationIsActive`, `EnsurePasswordIsChanged`, `AdminDisabledException`.

#### 10.2: REQ-BOF-005 — Audit of sensitive actions
The system shall record an append-only audit entry scoped to the organization with actor, subject and
before/after properties for every declared sensitive action, shall include platform actions performed on the
organization so the business can see them, and shall never abort a business operation because auditing
failed.
Expected identifiers: `LogsSensitiveActivity`, `AuditEvent`, `AuditLogController`, `activity_log.organization_id`.

---

### Requirement 11: Inventory, specification builder and catalog management

#### 11.1: REQ-BOF-020 — Product CRUD and financing terms
The system shall enforce coherent financing terms, shall keep SKU and slug unique per organization, shall
default installment count and frequency from the organization settings, shall require completeness before
publication, shall audit price changes, and shall count products against the plan limit.
Expected identifiers: `ProductData`, `ProductFormValues`, `UpsertProductAction`, `CoherentFinancingTerms`, `IncompleteProductException`, `PlanLimitReachedException`.

#### 11.2: REQ-BOF-021 — Dynamic technical specification builder
The system shall accept up to fifty specification rows with optional groups, shall recompute contiguous
positions on every save, shall replace the product specifications transactionally rather than diffing rows,
and shall suggest groups only from the same organization.
Expected identifiers: `SpecificationInput`, `SpecificationFormRow`, `SyncSpecificationsAction`, `SpecificationGroupsQuery`, `useSpecificationRows`.

#### 11.3: REQ-BOF-022 — Product image management
The system shall accept up to ten images per product with real MIME validation, shall persist explicit
ordering, shall promote the next image when the primary is deleted, shall free storage quota on deletion,
and shall reject a business upload when the storage quota is exhausted.
Expected identifiers: `SyncImageOrderAction`, `ImageSet`, `RealMimeType`, `StorageQuotaExceededException`.

#### 11.4: REQ-BOF-023 — Category and tag management
The system shall keep taxonomy per organization, shall keep categories flat and manually ordered, shall
prevent deleting a category that still has products, shall allow deleting a tag by detaching it, and shall
reuse an existing name on inline creation.
Expected identifiers: `Category`, `Tag`, `ReassignCategoryProductsAction`, `CategoryInUseException`.

#### 11.5: REQ-BOF-024 — Catalog management
The system shall validate the six publication preconditions, shall keep the slug immutable after
publication, shall show the derived visibility state and the shareable link, shall show the subscriber count
and the potential reach on the free plan, and shall not count drafts against the catalog plan limit.
Expected identifiers: `CatalogController`, `PublishCatalogAction`, `SyncCatalogProductsAction`, `CatalogVisibility`, `SlugLockedException`.

---

### Requirement 12: Customer directory, collections and exports

#### 12.1: REQ-BOF-040 — Customer directory
The system shall search customers by normalized document number, name, phone or email scoped to the
organization, shall aggregate balances without N+1 queries, shall order by collection priority, and shall
expose only a boolean indicator of a linked account and never its identifier.
Expected identifiers: `CustomerDirectoryQuery`, `CustomerDirectoryRow`, `CustomerDirectoryFilters`.

#### 12.2: REQ-BOF-041 — Customer profile and consolidated balance
The system shall present the customer's records, layaways, payments and receipts for this organization with
the same balance computation as the portal, shall expose full rejection and cancellation reasons to the
business, and shall never show the customer's activity in another organization.
Expected identifiers: `CustomerShowController`, `CustomerBalanceQuery`, `CustomerProfile`.

#### 12.3: REQ-BOF-042 — Collection filters
The system shall express every collection preset through a single filter object used by the view, the export
and the segmented push send, and shall compute all date boundaries with the organization's grace period and
time zone.
Expected identifiers: `CollectionFilters`, `CollectionPreset`, `CollectionRow`, `CollectionController`.

#### 12.4: REQ-BOF-043 — Asynchronous Excel export
The system shall require the export plan feature, shall always run exports on a queue with the tenant
explicitly set, shall rehydrate the requested filters inside the job, shall deliver the file through a signed
URL restricted to members of the owning organization, shall purge files after seven days, and shall never
include the account id.
Expected identifiers: `ExportJob`, `ExportType`, `ExportStatus`, `GenerateExport`, `CollectionsExport`, `CustomersExport`, `InventoryExport`, `PurgeExpiredExports`, `PlanFeatureUnavailableException`.

#### 12.5: REQ-BOF-044 — Business dashboard
The system shall compute the declared operational metrics with cached aggregate queries and isolated
failures, shall link each metric to the equivalent filtered listing, shall surface plan usage, and shall
raise the seven declared warnings, including a lost-sale warning only when a layaway was actually rejected
by a plan limit.
Expected identifiers: `DashboardMetrics`, `DashboardWarning`, `DashboardMetricsQuery`, `DashboardWarningsQuery`, `PlanState`.

#### 12.6: REQ-BOF-045 — Server-driven tables
The system shall use manual pagination, sorting and filtering with server-side data delivered through
partial reloads, shall restrict sorting to a server whitelist, shall clamp the page size, and shall degrade
to stacked cards on small screens without horizontal body scrolling.
Expected identifiers: `ServerTableState`, `useServerTable`, `DataTable`, `SortWhitelist`, `ResolvesTableState`, `Paginated`.

---

### Requirement 13: Web Push and email notifications

#### 13.1: REQ-NOT-001 — Web Push subscription with a polymorphic notifiable
The system shall attach a push subscription to the consumer account or to the customer record depending on
the session, shall resolve the effective recipient through a single resolver so a person with both
identities is never notified twice, shall request permission contextually, and shall not gate transactional
notifications behind a plan feature.
Expected identifiers: `PushSubscription`, `NotifiableResolver`, `NotificationAudience`, `PushSubscriptionState`, `usePushSubscription`, `NotificationType`.

#### 13.2: REQ-NOT-002 — Automatic collection reminders
The system shall send reminders at the organization's configured offsets, shall guarantee exactly-once
delivery per installment, type and channel through a unique database constraint, shall group installments of
one layaway and never across businesses, shall send at the organization's local hour, and shall name the
business in every message.
Expected identifiers: `DispatchDueRemindersAction`, `NotificationDispatch`, `ReminderPlan`, `InstallmentReminderNotification`.

#### 13.3: REQ-NOT-003 — Transactional notifications
The system shall dispatch transactional notifications only after the business transaction commits, shall
never revert an operation because a notification failed, shall name the business in every customer message,
and shall notify all active owners for subscription events.
Expected identifiers: `NotificationType`, `SendLayawayNotifications`, `SendSubscriptionNotifications`, `ReceiptApprovedNotification`, `ReceiptRejectedNotification`.

#### 13.4: REQ-NOT-004 — Segmented manual push
The system shall require the push plan feature, shall accept exactly one recipient mode, shall reuse the
collection filters for the segment, shall show the exact reachable count before sending, shall never fall
back to email, and shall reach only records of the sending organization.
Expected identifiers: `ManualPushData`, `SendManualPushAction`, `PushDeliveryReport`, `SendWebPush`.

#### 13.5: REQ-NOT-005 — Idempotency, sanitation and observability
The system shall record the dispatch before enqueuing and roll it back if enqueuing fails, shall purge a
subscription on a 404 or 410 response but not on 429, shall purge subscriptions unused for 180 days, and
shall surface failed jobs to the platform health screen.
Expected identifiers: `NotificationDispatch`, `CatalogDigestDispatch`, `PurgeStalePushSubscriptions`, `SendWebPush`.

---

### Requirement 14: Customer support chat

#### 14.1: REQ-CHT-001 — Chat widget and routing by catalog
The system shall derive the recipient organization from the catalog or portal context and never from the
request payload, shall keep one permanent thread per organization and customer, shall require a customer
record in that organization to write, and shall accept plain text messages only.
Expected identifiers: `Conversation`, `Message`, `MessageSenderType`, `SendMessageAction`, `ChatMessage`, `ChatThread`, `NoCustomerRecordException`.

#### 14.2: REQ-CHT-002 — Business inbox
The system shall scope the inbox to the organization, shall order unread threads first, shall present the
customer's layaway context for this organization only, and shall limit the visible history window on the
free plan without deleting messages.
Expected identifiers: `ConversationRow`, `ConversationListQuery`, `ThreadContextHeader`.

#### 14.3: REQ-CHT-003 — Real-time transport and degradation
The system shall name conversation channels with the organization id, shall authorize them only for the
record holder or an active member of that organization, shall broadcast after commit, and shall fall back to
polling with deduplication by message id.
Expected identifiers: `MessageSent`, `useRealtimeMessages`, `echo`, channel `organization.{orgId}.conversation.{id}`.

#### 14.4: REQ-CHT-004 — Read state, unread counters and typing indicator
The system shall maintain materialized unread counters updated atomically, shall mark messages read when the
counterpart opens the thread, shall emit the typing indicator as a throttled non-persisted event, and shall
count unread messages regardless of the plan's visible history window.
Expected identifiers: `Conversation::markReadBy`, `UserTyping`, `RecountConversations`, `useTypingIndicator`.

#### 14.5: REQ-CHT-005 — Unread message notification
The system shall enqueue a delayed notification for a team message, shall revalidate read state before
sending, shall group consecutive messages, shall name the business, and shall not gate this behind a plan
feature.
Expected identifiers: `NotifyUnreadMessage`, `ChatMessageNotification`, `NotificationType::ChatMessage`.

---

### Requirement 15: Telemetry and interactions

#### 15.1: REQ-TLM-001 — Closed event catalog and event store contract
The system shall accept only declared event names, shall sanitize payloads against per-event schemas, shall
derive the organization and catalog from a submitted catalog slug and never from a client-supplied
organization id, shall bound the accepted timestamp window, and shall keep the store append-only except for
retroactive linkage and retention purging.
Expected identifiers: `TelemetryEventName`, `TelemetryEvent`, `EventSanitizer`, `SanitizedEvent`.

#### 15.2: REQ-TLM-002 — Client buffer and batch ingestion
The system shall buffer events client-side and flush them by size, interval or page unload, the ingestion
endpoint shall only enqueue a job and return without writing to the database, and a batch containing events
of several organizations shall attribute each event to its own tenant.
Expected identifiers: `useTelemetry`, `TelemetryBuffer`, `TelemetryController`, `IngestBatchData`.

#### 15.3: REQ-TLM-003 — Asynchronous persistence and dwell time
The system shall bulk insert sanitized events from the lowest priority queue setting and clearing the tenant
per event, shall tolerate a single invalid event without failing the batch, and shall measure dwell time
only while the page is visible.
Expected identifiers: `ProcessTelemetryBatch`, `useDwellTime`, `dwell_time_recorded`.

#### 15.4: REQ-TLM-004 — Visitor identity and retroactive linkage
The system shall assign a first-party visitor cookie that survives logout of every guard, shall link prior
events to a customer record **scoped to that organization**, shall link prior events to a consumer account
across organizations, and shall bound both linkages to a retention window.
Expected identifiers: `AssignVisitorId`, `LinkVisitorEventsToCustomer`, `LinkVisitorEventsToAccount`, `telemetry_events.visitor_id`.

#### 15.5: REQ-TLM-005 — Privacy, consent, retention and business analytics
The system shall never store a raw IP address, shall gate non-essential events behind cookie consent, shall
retain event detail according to the organization's plan, shall preserve monthly aggregates before purging,
and shall expose only aggregated catalog analytics that never include the account id or activity in other
organizations.
Expected identifiers: `IpHasher`, `PurgeTelemetry`, `TelemetryMonthlyAggregate`, `CatalogAnalyticsQuery`, `CookieConsent`, `useConsent`, `ConsentLevel`.

---

### Requirement 16: Plans, limits and subscription billing

#### 16.1: REQ-SUB-001 — Plan catalog
The system shall store plans as data with explicit limit columns and a feature list, shall treat a null
limit as unlimited, shall impose no subscriber limit, shall include the layaway system in the free plan, and
shall render the public pricing page from the database.
Expected identifiers: `Plan`, `PlanFeature`, `UsageMetric`, `PlanSeeder`, `PlanState`.

#### 16.2: REQ-SUB-002 — Automatic free-plan enrollment
The system shall give every organization exactly one subscription created at registration on the free plan,
with no invoice, no period end and no payment data, and shall never leave an organization without a plan.
Expected identifiers: `Subscription`, `SubscriptionStatus`, `StartFreeSubscriptionAction`, `MissingFreePlanException`.

#### 16.3: REQ-SUB-003 — Usage measurement
The system shall materialize usage counters per organization and metric, shall update them inside the
mutating transaction, shall recalculate all metrics daily from scratch, shall define each metric exactly as
declared, and shall never leave a counter negative.
Expected identifiers: `UsageCounter`, `UsageMetric`, `UsageCalculator`, `UsageState`, `RecalculateUsage`.

#### 16.4: REQ-SUB-004 — Limit and feature enforcement
The system shall decide every limit and feature question in a single enforcer invoked inside the transaction
with the counter locked, shall enrich every limit exception with the metric, the current value, the ceiling
and the minimum resolving plan, shall present a neutral message to the end customer when a business hits the
active-layaway ceiling, and shall show unavailable features disabled rather than hidden.
Expected identifiers: `LimitEnforcer`, `PlanLimitReachedException`, `PlanFeatureUnavailableException`, `EnforcePlanLimit`, `usePlanLimits`.

#### 16.5: REQ-SUB-005 — Plan change
The system shall apply an upgrade immediately and issue its invoice at once, shall schedule a downgrade for
the end of the paid period, shall degrade a cancellation to the free plan rather than leaving no plan, and
shall preview exactly what will exceed the new limits before confirming.
Expected identifiers: `ChangePlanAction`, `PlanChangePreview`, `SubscriptionStatus`.

#### 16.6: REQ-SUB-006 — Billing and receipt-based collection
The system shall collect subscriptions manually by receipt reusing the product's receipt collaborators,
shall keep an issued invoice immutable, shall denominate invoices in USD converted with the platform's rate
source, and shall issue renewal invoices seven days before period end.
Expected identifiers: `SubscriptionInvoice`, `SubscriptionReceipt`, `InvoiceStatus`, `InvoiceRow`, `IssueInvoiceAction`, `ApproveSubscriptionReceiptAction`, `IssueRenewalInvoices`.

#### 16.7: REQ-SUB-007 — Non-payment, grace and suspension
The system shall move an overdue subscription to past due with a grace window and then to suspended, shall
freeze the grace while a subscription receipt is under review, and shall never let non-payment interrupt an
active layaway, the customer portal, receipt submission, the customer chat or collection reminders.
Expected identifiers: `ProcessSubscriptionDunning`, `SubscriptionStatus`, `subscriptions.grace_ends_at`, `InvoiceOverdueNotification`.

#### 16.8: REQ-SUB-008 — Non-destructive downgrade
The system shall never delete or hide existing data on downgrade, shall only unpublish catalogs exceeding
the new ceiling after a grace period keeping the oldest, shall never touch active layaways, team members or
products, shall preserve feature configuration for reactivation, and shall accept a customer receipt even
when the storage quota is exhausted while rejecting a business upload.
Expected identifiers: `ApplyDowngradeAction`, `DowngradeOutcome`, `ApplyScheduledDowngrades`, `PlanChangePreview`.

---

### Requirement 17: Platform backoffice

#### 17.1: REQ-PLT-001 — Platform backoffice access
The system shall authenticate platform operators through a separate table and guard with no relation to team
users, shall create the first operator from an idempotent seeder reading environment variables, and shall
grant no organization access without a support grant.
Expected identifiers: `PlatformUser`, `PlatformAdminSeeder`, `EnsurePlatformUserIsActive`, guard `platform`.

#### 17.2: REQ-PLT-002 — Organization directory
The system shall list organizations with their plan, subscription status and aggregated counts and volumes,
shall never show end-customer personal data without an active support grant, shall highlight the declared
risk signals, and shall confine unscoped queries to the platform namespaces.
Expected identifiers: `OrganizationRow`, `OrganizationDirectoryQuery`, `OrganizationDirectoryFilters`.

#### 17.3: REQ-PLT-003 — Platform metrics
The system shall compute organization, plan distribution, MRR, invoice, network, transacted volume and
health metrics as anonymous aggregates, shall define MRR over active and past-due subscriptions, and shall
report the count of accounts following catalogs of more than one business as the network KPI.
Expected identifiers: `PlatformMetrics`, `PlatformMetricsQuery`.

#### 17.4: REQ-PLT-004 — Subscription and collection management
The system shall provide a subscription receipt inbox with the same mechanics as the customer receipt inbox,
shall allow manual plan changes, invoice voiding and courtesy period extensions with a mandatory reason, and
shall make every such action visible in the affected organization's own audit log.
Expected identifiers: `InvoiceRow`, `GrantCourtesyPeriodAction`, `ApproveSubscriptionReceiptAction`, `InvoiceAlreadyPaidException`.

#### 17.5: REQ-PLT-005 — Audited support access
The system shall require a time-limited grant with a mandatory reason to read an organization's data, shall
grant read-only access scoped to one organization, shall record the grant in that organization's audit log
and notify its owners, and shall stop granting access on the next request after expiry or revocation.
Expected identifiers: `SupportAccessGrant`, `GrantSupportAccessAction`, `RevokeSupportAccessAction`, `RequiresSupportAccess`, `SupportAccessRequiredException`, `SupportAccessGrantedNotification`.

#### 17.6: REQ-PLT-006 — System health
The system shall report queue depth and failures, exchange rate age, scheduled command freshness, storage,
websocket connectivity and reconciliation discrepancies without caching, shall mark a scheduled command
overdue at twice its interval, and shall treat reconciliation discrepancies as the most severe signal.
Expected identifiers: `SystemHealth`, `SystemHealthQuery`.

---

### Requirement 18: Infrastructure, Docker, Traefik and queues

#### 18.1: REQ-INF-001 — Docker compose topology
The system shall run isolated services for app, web server, database, cache, queue worker and websocket
server, shall keep the database and cache off the public network, and shall run migrations only from the app
service.
Expected identifiers: `docker-compose.yml`, `Dockerfile`, `docker/entrypoint.sh`, volume `app_storage`, network `traefik_proxy`.

#### 18.2: REQ-INF-002 — Traefik integration, domains and TLS
The system shall publish the site and the websocket host through labels on an externally managed Traefik
instance, shall obtain TLS certificates automatically, and shall trust proxy headers only from that network.
Expected identifiers: `TrustProxies`, traefik router labels, `ws.apartar.store`.

#### 18.3: REQ-INF-003 — Prioritized queues and scheduled tasks
The system shall consume queues in strict priority order, shall pass the organization explicitly to every
job touching a tenant table and clear it afterwards, shall fail a job that touches a tenant table without a
tenant, and shall run per-organization scheduled commands hourly filtering by each organization's local
hour.
Expected identifiers: queues `high`, `default`, `exports`, `telemetry`; `RunsInTenantContext`; scheduled commands `layaways:expire`, `layaways:detect-overdue`, `notifications:dispatch-reminders`, `catalogs:dispatch-digests`, `layaways:reconcile`, `subscriptions:process-dunning`, `subscriptions:apply-downgrades`, `subscriptions:recalculate-usage`, `telemetry:purge`.

#### 18.4: REQ-INF-004 — Environment configuration and drivers
The system shall use Redis for cache, session, queue and broadcasting in every environment, shall store UTC
while presenting in local time, and shall refuse to boot in production with debug enabled.
Expected identifiers: `config/cache.php`, `config/session.php`, `config/queue.php`, `config/broadcasting.php`, `APP_TIMEZONE`.

#### 18.5: REQ-INF-005 — Reverb websocket service
The system shall run the websocket server as its own service serving all organizations with isolation
enforced by channel authorization, shall broadcast over the internal network while clients connect to the
public host, and shall degrade to polling when unavailable.
Expected identifiers: `config/reverb.php`, `routes/channels.php`, `MessageSent`, channel `organization.{orgId}.receipts`.

#### 18.6: REQ-INF-006 — Build, deployment and migrations
The system shall build a multi-stage image without development dependencies, shall compile assets at build
time, shall run migrations once per deployment, and shall keep migrations backward compatible within a
deployment.
Expected identifiers: `Dockerfile`, `Makefile`, `docker/entrypoint.sh`, `docs/runbook-despliegue.md`.

---

### Requirement 19: Standards, testing and SDD conformance gates

#### 19.1: REQ-QAX-001 — Backend code standards
The system shall keep controllers thin, shall place every multi-table mutation in a transactional action,
shall keep business rules out of models, and shall pass static analysis at the declared level.
Expected identifiers: `pint.json`, `phpstan.neon`, `app/Domain`, `app/Actions`, `declare(strict_types=1)`.

#### 19.2: REQ-QAX-002 — Frontend code standards
The system shall keep every component under the declared line limit, shall place logic in custom hooks,
shall forbid `any` under strict TypeScript, and shall confine each TanStack library to its declared use.
Expected identifiers: `eslint.config.js`, `tsconfig.json`, `max-lines`, `resources/js/features`, `domain.ts`.

#### 19.3: REQ-QAX-003 — Testing strategy
The system shall cover the domain and actions to the declared threshold, shall verify the three arithmetic
invariants by property tests, shall test rollback for every transactional action, shall test the declared
concurrency and equivalence cases on a real MySQL database, and **shall seed a neighbouring organization in
every factory and feature test** so isolation failures surface by default.
Expected identifiers: `phpunit.xml`, `tests/Unit/Domain`, `tests/Feature`, `CreatesNeighbourOrganization`, `vitest.config.ts`.

#### 19.4: REQ-QAX-004 — Continuous integration pipeline
The system shall verify every pull request with three parallel jobs covering backend quality, frontend
quality and tenant isolation, shall not mark any step as continue-on-error, and shall not deploy.
Expected identifiers: `.github/workflows/ci.yml`, `.env.ci`, job `tenancy`.

#### 19.5: REQ-QAX-005 — English requirements mirror for spec alignment
The system shall maintain a generated English requirements mirror consumed by the alignment tooling, keeping
the same requirement identifiers as the Spanish source, and shall never edit the mirror by hand.
Expected identifiers: `docs/specs/.align/requirements.en.md`, `.kiro/specs/apartar/requirements.md`.

#### 19.6: REQ-QAX-006 — Conformance gates and drift policy
The system shall verify each abstraction hop separately, shall tolerate shallow matches in the types phase
and require zero shallow matches in the logic phase, and shall resolve shallow matches by adding non-trivial
functions in the same file as the matched type.
Expected identifiers: `indexion spec align diff`, `indexion spec align status`, `.indexion/sdd-reports/apartar`.

#### 19.7: REQ-QAX-007 — Mandatory technical documentation
The system shall document every class, interface, enum, public method and promoted property with PHPDoc
using the specification vocabulary, shall document every hook and exported type with TSDoc, and shall omit
obvious inline comments.
Expected identifiers: PHPDoc on `app/Domain` and `app/Actions`; TSDoc on `resources/js/features/*/hooks`.

#### 19.8: REQ-QAX-008 — Multi-tenant isolation gate
The system shall provide a cross-tenant leakage test for every tenant endpoint derived from the route table,
shall require 404 rather than 403 for a foreign resource, shall cover backoffice, public catalog, portal,
broadcast channels, jobs, signed files, platform namespaces, telemetry ingestion and the feed, shall audit
statically that every tenant model uses the tenant trait and every business unique index includes the
organization id except three whitelisted exceptions, shall verify that no response or export exposes the
account id, and shall run as its own CI job with no exceptions permitted.
Expected identifiers: `CreatesNeighbourOrganization`, `TenancyAudit`, `GlobalUniqueExceptions`, `CrossTenantAccessTest`, `BroadcastChannelIsolationTest`, `JobTenantContextTest`, `AccountBoundaryTest`.
