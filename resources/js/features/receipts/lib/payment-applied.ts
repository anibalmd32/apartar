import { type PaymentRow } from '@/types/receipt';

export function installmentList(numbers: readonly number[]): string | null {
    if (numbers.length === 0) {
        return null;
    }

    if (numbers.length === 1) {
        return `cuota ${numbers[0]}`;
    }

    return `cuotas ${numbers.slice(0, -1).join(', ')} y ${numbers[numbers.length - 1]}`;
}

export function appliedTo(payment: PaymentRow): string | null {
    const parts: string[] = [];
    const installments = installmentList(payment.appliedTo);

    if (payment.downPayment !== null) {
        const onlyDownPayment = installments === null && payment.unallocated === null;
        parts.push(onlyDownPayment ? 'a la inicial' : `${payment.downPayment} a la inicial`);
    }

    if (installments !== null) {
        parts.push(installments);
    }

    if (payment.unallocated !== null) {
        parts.push(`${payment.unallocated} sin asignar`);
    }

    return parts.length === 0 ? null : parts.join(', ');
}
