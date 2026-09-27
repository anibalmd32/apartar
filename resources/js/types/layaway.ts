import { type CatalogView, type ProductDetail } from '@/types/catalog-view';
import { type MoneyView } from '@/types/money';
import { type PaymentAccountView } from '@/types/payment';

/**
 * Lo que el formulario de apartado manda (contrato `LayawayFormValues` de
 * `REQ-LAY-001`).
 *
 * `idempotencyKey` se genera **una vez al montar** el formulario y viaja igual
 * en cada envío (RB-12): es lo que permite al servidor reconocer un doble clic y
 * devolver el mismo apartado. Si se regenerase en cada envío, dos pulsaciones
 * serían dos apartados.
 */
export interface LayawayFormValues {
    [key: string]: string | number | boolean;
    quantity: number;
    fullName: string;
    documentNumber: string;
    /** Obligatorio. */
    phone: string;
    /** Opcional: en blanco es sin correo. */
    email: string;
    termsAccepted: boolean;
    idempotencyKey: string;
}

/** Una cuota del resumen, con su fecha **estimada**: cuenta desde la activación. */
export interface LayawayPlanInstallment {
    readonly number: number;
    readonly estimatedDueDate: string;
    readonly amount: MoneyView;
}

/**
 * El plan del apartado entero, calculado en el servidor sobre la cantidad
 * elegida (`LayawayPlanResource`). No es la cuota de la ficha multiplicada:
 * `allocateEvenly()` no es lineal, y aquí no llega nada con lo que dividir.
 */
export interface LayawayPlanView {
    readonly quantity: number;
    readonly total: MoneyView;
    readonly downPayment: MoneyView;
    readonly financedAmount: MoneyView;
    readonly installmentsCount: number;
    readonly frequencyDays: number;
    readonly installmentAmount: MoneyView | null;
    /** La última cuota cuando el reparto no es exacto; `null` si son todas iguales. */
    readonly lastInstallmentAmount: MoneyView | null;
    readonly installments: readonly LayawayPlanInstallment[];
}

/**
 * Lo que necesita la casilla de consentimiento (`REQ-IDN-005`).
 *
 * `platformTermsDrafted` es la marca de `LegalController`: mientras los
 * documentos de la plataforma no estén redactados, la casilla **no los enlaza
 * ni los nombra** (decisión de `T-045b`, escrita bajo `REQ-IDN-005`).
 */
export interface LayawayConsentView {
    readonly businessName: string;
    readonly platformTermsDrafted: boolean;
}

/** Las props de `catalog/LayawayCreate`. */
export interface LayawayCreateProps {
    readonly catalog: CatalogView;
    readonly product: ProductDetail;
    readonly plan: LayawayPlanView | null;
    readonly quantity: number;
    readonly consent: LayawayConsentView;
    /** El censo de prefijos del servidor, como dato, para el aviso que no bloquea. */
    readonly recognisedAreaCodes: readonly string[];
}

/** Las props de `catalog/LayawayThanks`. */
export interface LayawayThanksProps {
    readonly catalog: CatalogView;
    readonly layaway: {
        readonly code: string;
        readonly productName: string;
        readonly plan: LayawayPlanView;
        /** El plazo de la inicial, ya formateado en la zona del negocio. */
        readonly expiresAt: string | null;
        /** Sin correo, esta pantalla es el único canal (RB-10). */
        readonly hasEmail: boolean;
    };
    readonly paymentAccounts: readonly PaymentAccountView[];
    /** La subida del comprobante (`T-059`); `null` hasta que exista. */
    readonly receiptUrl: string | null;
}
