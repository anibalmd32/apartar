import { BYTES_PER_MB } from '@/features/receipts/hooks/useFilePreview';
import { type ReceiptReading, useReceiptReading } from '@/features/receipts/hooks/useReceiptReading';
import { amountInput } from '@/lib/money';
import { type Currency } from '@/types/money';
import { type ReceiptCreateProps, type ReceiptFormValues, type ReceiptSuggestion } from '@/types/receipt';
import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';

type UseReceiptFormOptions = Pick<
    ReceiptCreateProps,
    'paymentAccounts' | 'methods' | 'currencies' | 'suggestion' | 'paidAt' | 'file' | 'storeUrl' | 'readUrl'
>;

export function suggestedAmount(suggestion: ReceiptSuggestion | null, currency: Currency): string {
    if (suggestion === null) {
        return '';
    }

    return amountInput(currency === 'USD' ? suggestion.amountUsd : (suggestion.amountVes ?? ''));
}

export type AutofilledField = 'amount' | 'paidAt' | 'bank' | 'reference';

const NUMERIC_REFERENCE_METHODS: readonly string[] = ['pago_movil', 'transferencia'];

function missingFields(data: ReceiptFormValues, requiresReference: boolean, needsMethod: boolean): Record<string, string> {
    const errors: Record<string, string> = {};

    if (needsMethod && data.method === '') {
        errors.method = 'Elige cómo pagaste.';
    }

    if (data.file === null) {
        errors.file = 'Sube la captura o el PDF de tu pago.';
    }

    if (requiresReference && data.bank.trim() === '') {
        errors.bank = 'Indica el banco desde el que pagaste.';
    }

    if (requiresReference && data.reference.trim() === '') {
        errors.reference = 'Escribe el número de referencia.';
    }

    if (data.amount.trim() === '') {
        errors.amount = 'Escribe el monto que pagaste.';
    }

    return errors;
}

export function useReceiptForm({ paymentAccounts, methods, currencies, suggestion, paidAt, file, storeUrl, readUrl }: UseReceiptFormOptions) {
    const findMethod = (value: string) => methods.find((method) => method.value === value);

    const initialAccount = paymentAccounts.length === 1 ? paymentAccounts[0] : undefined;
    const initialMethod = initialAccount ? findMethod(initialAccount.method) : undefined;
    const initialCurrency: Currency = initialMethod?.defaultCurrency ?? currencies[0]?.value ?? 'USD';

    const form = useForm<ReceiptFormValues>({
        paymentAccount: initialAccount?.id ?? null,
        method: initialMethod?.value ?? '',
        bank: '',
        reference: '',
        paidAt: paidAt.max,
        amount: suggestedAmount(suggestion, initialCurrency),
        currency: initialCurrency,
        note: '',
        file: null,
    });

    const amountTyped = useRef(false);
    const [autofilled, setAutofilled] = useState<readonly AutofilledField[]>([]);
    const currencyChosen = useRef(false);

    const withCurrency = (data: ReceiptFormValues, currency: Currency): ReceiptFormValues => ({
        ...data,
        currency,
        amount: amountTyped.current ? data.amount : suggestedAmount(suggestion, currency),
    });

    const withMethod = (data: ReceiptFormValues, value: string): ReceiptFormValues => {
        const method = findMethod(value);
        const next: ReceiptFormValues = method?.requiresReference ? { ...data } : { ...data, bank: '', reference: '' };
        next.method = method?.value ?? '';

        const suggestsCurrency = method !== undefined && !currencyChosen.current && !amountTyped.current;

        return suggestsCurrency ? withCurrency(next, method.defaultCurrency) : next;
    };

    const selectAccount = (value: string) => {
        const account = paymentAccounts.find((candidate) => String(candidate.id) === value);

        if (account !== undefined) {
            form.clearErrors('paymentAccount');
        }

        form.setData((data) => {
            const next = { ...data, paymentAccount: account?.id ?? null };
            const changesMethod = account !== undefined && account.method !== data.method && findMethod(account.method) !== undefined;

            return changesMethod ? withMethod(next, account.method) : next;
        });
    };

    const selectMethod = (value: string) => form.setData((data) => withMethod(data, value));

    const selectCurrency = (value: string) => {
        const currency = currencies.find((candidate) => candidate.value === value);

        if (currency) {
            currencyChosen.current = true;
            form.setData((data) => withCurrency(data, currency.value));
        }
    };

    const setAmount = (value: string) => {
        amountTyped.current = value.trim() !== '';
        form.setData('amount', value);
    };

    const applyReading = (reading: ReceiptReading) => {
        amountTyped.current = reading.amount !== null;

        if (reading.currency !== null) {
            currencyChosen.current = true;
        }

        const takes = findMethod(form.data.method)?.requiresReference ?? false;
        const found: [AutofilledField, string | null][] = [
            ['amount', reading.amount],
            ['paidAt', reading.paidAt],
            ['bank', takes ? reading.bank : null],
            ['reference', takes ? reading.reference : null],
        ];
        const filled = found.filter(([, value]) => value !== null && value !== '').map(([field]) => field);
        setAutofilled(filled);

        if (filled.length > 0) {
            form.clearErrors(...filled);
        }

        form.setData((data) => {
            const takesReference = findMethod(data.method)?.requiresReference ?? false;

            return {
                ...data,
                amount: reading.amount === null ? '' : amountInput(reading.amount),
                currency: reading.currency ?? data.currency,
                paidAt: reading.paidAt ?? data.paidAt,
                bank: takesReference ? (reading.bank ?? '') : data.bank,
                reference: takesReference ? (reading.reference ?? '') : data.reference,
            };
        });
    };

    const reading = useReceiptReading(readUrl, applyReading);

    const setFile = (value: File | null) => {
        form.setData('file', value);
        form.clearErrors('file');
        setAutofilled([]);
        reading.read(value !== null && value.size <= file.maxMb * BYTES_PER_MB ? value : null);
    };

    const submit = (): boolean => {
        const requiresReference = findMethod(form.data.method)?.requiresReference ?? false;
        const missing = missingFields(form.data, requiresReference, paymentAccounts.length === 0 || form.data.paymentAccount === null);

        form.clearErrors();

        if (Object.keys(missing).length > 0) {
            form.setError(missing);

            return false;
        }

        form.post(storeUrl, { forceFormData: true, preserveScroll: true });

        return true;
    };

    const { data } = form;

    return {
        ...form,
        selectAccount,
        selectMethod,
        selectCurrency,
        setAmount,
        setFile,
        submit,
        selectedAccount: paymentAccounts.find((account) => account.id === data.paymentAccount) ?? null,
        requiresReference: findMethod(data.method)?.requiresReference ?? false,
        numericReference: NUMERIC_REFERENCE_METHODS.includes(data.method),
        autofilled,
        fileTooLarge: data.file !== null && data.file.size > file.maxMb * BYTES_PER_MB,
        reading: reading.state,
    };
}

export type ReceiptForm = ReturnType<typeof useReceiptForm>;
