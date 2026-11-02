import { type ReceiptStatus } from '@/types/receipt';

export interface ReceiptRow {
    readonly id: string;
    readonly submittedAt: string;
    readonly method: string;
    readonly reference: string | null;
    readonly declaredAmount: string;
    readonly paidToAccount: string | null;
    readonly status: ReceiptStatus;
    readonly statusLabel: string;
    readonly rejectionReason: string | null;
    readonly fileUrl: string;
    readonly replacesReceiptId: string | null;
}
