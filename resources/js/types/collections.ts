import { type TableMeta } from '@/types/data-table';
import { type MoneyView } from '@/types/money';

/** El cliente de una fila de la cartera: nombre y teléfono, el canal real de cobro. */
export interface CollectionCustomer {
    readonly name: string;
    /** E.164, para copiarlo o marcarlo. */
    readonly phone: string;
    readonly phoneLabel: string;
}

/** `REQ-BOF-042` RB-6 · una cuota sin cubrir. */
export interface CollectionInstallmentRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly number: number;
    readonly dueDate: string;
    readonly amount: MoneyView;
    readonly paid: MoneyView;
    /** Días desde el vencimiento, en el calendario del negocio; `0` si no ha vencido. */
    readonly daysLate: number;
}

/** `completed_not_delivered` · un apartado pagado que falta entregar. */
export interface CollectionLayawayRow {
    readonly id: number;
    readonly customer: CollectionCustomer | null;
    readonly layaway: { readonly code: string; readonly url: string };
    readonly productName: string;
    readonly completedAt: string | null;
    readonly total: MoneyView;
}

export interface CollectionFiltersState {
    readonly preset: string | null;
    readonly from: string | null;
    readonly to: string | null;
    readonly status: string | null;
    readonly q: string | null;
    readonly category: string | null;
    readonly catalog: string | null;
}

interface Option {
    readonly value: string;
    readonly label: string;
}

export interface CollectionOptions {
    readonly presets: readonly Option[];
    readonly statuses: readonly Option[];
    readonly categories: readonly Option[];
    readonly catalogs: readonly Option[];
}

interface CollectionPropsBase {
    readonly meta: TableMeta;
    /** La definición exacta del preset activo, para el estado vacío (SP-1). */
    readonly definition: string | null;
    readonly options: CollectionOptions;
}

export type CollectionIndexProps =
    | (CollectionPropsBase & { readonly kind: 'installments'; readonly rows: readonly CollectionInstallmentRow[] })
    | (CollectionPropsBase & { readonly kind: 'layaways'; readonly rows: readonly CollectionLayawayRow[] });
