import { IconChip } from '@/components/ui/icon-chip';
import { PAYMENT_METHOD_ICONS } from '@/features/catalog/components/PaymentMethodsCard';
import { cn } from '@/lib/utils';
import { type PaymentAccountView } from '@/types/payment';
import { type ReceiptCurrencyOption } from '@/types/receipt';
import { useId } from 'react';

function FieldErrors({ id, error }: { id: string; error?: string }) {
    return error ? (
        <p id={`${id}-error`} role="alert" className="label text-error text-xs">
            {error}
        </p>
    ) : null;
}

interface AccountChoiceProps {
    accounts: readonly PaymentAccountView[];
    value: number | null;
    onChange: (value: string) => void;
    label: (account: PaymentAccountView) => string;
    error?: string;
}

export function PaymentAccountChoice({ accounts, value, onChange, label, error }: AccountChoiceProps) {
    const id = useId();

    return (
        <fieldset className="fieldset w-full" aria-describedby={error ? `${id}-error` : undefined}>
            <legend className="fieldset-legend">Cuenta a la que pagaste</legend>

            <div className="flex flex-col gap-2">
                {accounts.map((account) => {
                    const checked = value === account.id;

                    return (
                        <label
                            key={account.id}
                            className={cn(
                                'has-[:focus-visible]:outline-primary flex cursor-pointer items-center gap-2.5 rounded-xl border p-3 outline-offset-2 has-[:focus-visible]:outline-2',
                                checked ? 'border-primary bg-primary-soft' : 'border-line-strong bg-base-100',
                            )}
                        >
                            <input
                                type="radio"
                                name="paymentAccount"
                                value={String(account.id)}
                                checked={checked}
                                onChange={(event) => onChange(event.target.value)}
                                className="radio radio-primary radio-sm"
                                aria-label={label(account)}
                            />
                            <IconChip icon={PAYMENT_METHOD_ICONS[account.method]} size={30} />
                            <span className="flex min-w-0 flex-col" aria-hidden="true">
                                <span className="text-[13px] font-semibold">
                                    {account.methodLabel}
                                    {account.bank !== null ? ` · ${account.bank}` : null}
                                </span>
                                <span className="text-ink-3 text-xs break-all">{account.identifier}</span>
                            </span>
                        </label>
                    );
                })}
            </div>

            <FieldErrors id={id} error={error} />
        </fieldset>
    );
}

interface CurrencyChoiceProps {
    currencies: readonly ReceiptCurrencyOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
}

export function CurrencyChoice({ currencies, value, onChange, error }: CurrencyChoiceProps) {
    const id = useId();

    return (
        <fieldset className="fieldset" aria-describedby={error ? `${id}-error` : undefined}>
            <legend className="fieldset-legend">Moneda</legend>

            <div className="bg-surface-2 flex h-10 w-fit items-center gap-0.5 rounded-[10px] p-[3px]">
                {currencies.map((currency) => {
                    const checked = value === currency.value;

                    return (
                        <label
                            key={currency.value}
                            className={cn(
                                'has-[:focus-visible]:outline-primary flex h-full cursor-pointer items-center rounded-lg px-3.5 text-[13px] outline-offset-1 has-[:focus-visible]:outline-2',
                                checked ? 'bg-base-100 shadow-card font-bold' : 'text-ink-3 font-medium',
                            )}
                        >
                            <input
                                type="radio"
                                name="currency"
                                value={currency.value}
                                checked={checked}
                                onChange={(event) => onChange(event.target.value)}
                                className="sr-only"
                            />
                            {currency.label}
                        </label>
                    );
                })}
            </div>

            <FieldErrors id={id} error={error} />
        </fieldset>
    );
}
