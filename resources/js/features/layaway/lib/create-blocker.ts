export interface CreateBlockerInput {
    hasProduct: boolean;
    customerMode: 'existing' | 'new';
    customer: string;
    firstName: string;
    termsAccepted: boolean;
    windowMissing: boolean;
    withPayment: boolean;
    reading: boolean;
    paymentMissing: string | null;
}

export function createBlocker(input: CreateBlockerInput): string | null {
    const existing = input.customerMode === 'existing';
    const checks: readonly [boolean, string][] = [
        [!input.hasProduct, 'Elige el producto que se aparta.'],
        [existing && input.customer === '', 'Busca y elige al cliente, o dalo de alta como cliente nuevo.'],
        [!existing && input.firstName.trim() === '', 'Escribe el nombre del cliente nuevo.'],
        [!existing && !input.termsAccepted, 'Marca que el cliente aceptó las condiciones del apartado.'],
        [input.windowMissing, 'Indica en cuántas horas o días vence el plazo para la inicial.'],
        [input.withPayment && input.reading, 'Espera a que terminemos de validar el comprobante.'],
    ];

    return checks.find(([pending]) => pending)?.[1] ?? (input.withPayment ? input.paymentMissing : null);
}
