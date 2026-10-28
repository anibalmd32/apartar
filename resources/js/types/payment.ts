export type PaymentMethod = 'pago_movil' | 'transferencia' | 'zelle' | 'binance_pay' | 'efectivo_usd' | 'efectivo_ves';

export interface PaymentMethodDefinition {
    readonly value: PaymentMethod;
    readonly label: string;
    readonly identifierLabel: string;
    readonly isCash: boolean;
}

export interface PaymentAccountView {
    readonly id: number;
    readonly method: PaymentMethod;
    readonly methodLabel: string;
    readonly identifierLabel: string;
    readonly bank: string | null;
    readonly bankLogoUrl?: string | null;
    readonly bankCode?: string | null;
    readonly holderName: string;
    readonly holderDocument: string;
    readonly identifier: string;
    readonly instructions: string | null;
    readonly isActive: boolean;
    readonly position: number;
}
