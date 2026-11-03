import { type MoneyView } from '@/types/money';
import { type PaymentEvidence } from '@/types/payment';

export interface PaymentRow extends PaymentEvidence {
    readonly settledAt: string;
    readonly amountUsd: MoneyView;
    readonly declaredAmount: string;
    readonly rateApplied: string | null;
    readonly rateSource: 'bcv' | 'parallel' | 'manual';
    readonly rateSourceLabel: string;
    readonly method: string;
    readonly appliedTo: readonly number[];
    readonly downPayment: string | null;
    readonly unallocated: string | null;
    readonly registeredByBusiness: boolean;
    readonly note: string | null;
    readonly fromReceipt?: boolean;
    readonly voided?: PaymentVoid | null;
    readonly voidUrl?: string | null;
}

export interface PaymentVoid {
    readonly at: string;
    readonly by: string | null;
    readonly reason: string | null;
}
