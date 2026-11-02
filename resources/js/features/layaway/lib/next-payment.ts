import { type NextPaymentSuggestion } from '@/types/portal';

type SuggestionWhat = Pick<NextPaymentSuggestion, 'kind' | 'installmentNumber' | 'overdueInstallments' | 'started'>;

export function suggestionWhat({ kind, installmentNumber, overdueInstallments, started }: SuggestionWhat): string {
    const rest = started ? 'lo que falta de ' : '';

    if (kind === 'down_payment') {
        return `${rest}la inicial`;
    }

    if (kind === 'overdue') {
        return overdueInstallments === 1 ? `${rest}tu cuota vencida` : `${rest}tus ${overdueInstallments} cuotas vencidas`;
    }

    return `${rest}la cuota ${installmentNumber ?? ''}`.trim();
}

export function capitalized(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}
