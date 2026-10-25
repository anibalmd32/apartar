import { centsUsd } from '@/lib/money';
import { type CollectionIndexProps, type CollectionInstallmentRow } from '@/types/collections';
import { type CustomerDirectoryRow, type CustomerIndexProps, type CustomerShowProps } from '@/types/customers';
import { type DashboardProps } from '@/types/dashboard';
import { type TableMeta } from '@/types/data-table';
import { type MoneyView } from '@/types/money';

export const money = (usd: string, cents: number): MoneyView => ({ cents: centsUsd(cents), usd, ves: null });

export function meta(filters: Record<string, string | null> = {}): TableMeta {
    return { page: 1, perPage: 25, sort: null, filters, total: null, lastPage: null, hasMore: false, ignoredFilters: [] };
}

export const CUSTOMER = { name: 'María González', phone: '+584141234567', phoneLabel: '0414-1234567' } as const;

export function installmentRow(overrides: Partial<CollectionInstallmentRow> = {}): CollectionInstallmentRow {
    return {
        id: 1,
        customer: CUSTOMER,
        layaway: { code: 'AP-7K3M9QRT', url: '/backoffice/apartados/AP-7K3M9QRT' },
        number: 2,
        dueDate: '2026-09-05',
        amount: money('$30,00', 3_000),
        paid: money('$10,00', 1_000),
        daysLate: 5,
        ...overrides,
    };
}

const COLLECTION_FILTERS = { preset: null, from: null, to: null, status: null, q: null, category: null, catalog: null };

export function collectionProps(
    rows: readonly CollectionInstallmentRow[],
    filters: Record<string, string | null> = {},
    definition: string | null = null,
): CollectionIndexProps {
    return {
        kind: 'installments',
        rows,
        meta: meta({ ...COLLECTION_FILTERS, ...filters }),
        definition,
        options: {
            presets: [
                { value: 'due_today', label: 'Vencen hoy' },
                { value: 'completed_not_delivered', label: 'Pagados sin entregar' },
            ],
            statuses: [{ value: 'active', label: 'Activo' }],
            categories: [],
            catalogs: [],
        },
    };
}

export function directoryRow(overrides: Partial<CustomerDirectoryRow> = {}): CustomerDirectoryRow {
    return {
        id: 7,
        url: '/backoffice/clientes/7',
        name: 'María González',
        document: 'V-12.345.678',
        phone: '+584141234567',
        phoneLabel: '0414-1234567',
        hasLinkedAccount: false,
        activeLayaways: 2,
        committed: money('$400,00', 40_000),
        paid: money('$130,00', 13_000),
        remaining: money('$270,00', 27_000),
        overdueInstallments: 1,
        inArrears: true,
        nextDueDate: '2026-09-05',
        ...overrides,
    };
}

const DIRECTORY_FILTERS = { q: null, overdue: null, due_soon: null, without_active: null, from: null, to: null, catalog: null };

export function directoryProps(rows: readonly CustomerDirectoryRow[], filters: Record<string, string | null> = {}): CustomerIndexProps {
    return { rows, meta: meta({ ...DIRECTORY_FILTERS, ...filters }), options: { catalogs: [] } };
}

export function profileProps(overrides: Partial<CustomerShowProps> = {}): CustomerShowProps {
    return {
        customer: {
            id: 7,
            name: 'María González',
            document: 'V-12.345.678',
            phone: '+584141234567',
            phoneLabel: '0414-1234567',
            email: null,
            createdAt: '2026-09-01',
            termsAcceptedAt: '2026-09-01',
            hasLinkedAccount: false,
        },
        totals: {
            committed: money('$200,00', 20_000),
            paid: money('$80,00', 8_000),
            remaining: money('$120,00', 12_000),
            overdueInstallments: 1,
            overdueAmount: money('$30,00', 3_000),
            nextDueDate: '2026-09-05',
        },
        layaways: { active: [], history: [], historyTotal: 0 },
        lists: { paymentsTotal: 0, receiptsTotal: 0, moreUrl: null },
        payments: [],
        receipts: [],
        conversationId: null,
        subscriptions: [],
        merge: { url: '/backoffice/clientes/7/fusionar', candidatesUrl: '/backoffice/clientes/7/fusionar/candidatas' },
        ...overrides,
    };
}

export function dashboardProps(overrides: Partial<DashboardProps['metrics']> = {}): DashboardProps {
    return {
        metrics: {
            pendingReceipts: 6,
            overdueReceiptsCount: 2,
            collectedThisMonth: money('$1.250,00', 125_000),
            dueThisWeek: { count: 4, amount: money('$120,00', 12_000) },
            customersOverdue: 3,
            longOverdueLayaways: 1,
            completedNotDelivered: 2,
            unreadMessages: null,
            rate: null,
            plan: null,
            warnings: [],
            subscribers: null,
            setup: { emailVerified: true, paymentAccount: true, product: true, publishedCatalog: true },
            ...overrides,
        },
        links: {
            receipts: '/backoffice/comprobantes?status=pending_review',
            dueThisWeek: '/backoffice/cobranza?preset=due_within_7_days',
            customersOverdue: '/backoffice/clientes?overdue=1',
            longOverdue: '/backoffice/cobranza?preset=overdue_over_15_days',
            completedNotDelivered: '/backoffice/cobranza?preset=completed_not_delivered',
        },
    };
}
