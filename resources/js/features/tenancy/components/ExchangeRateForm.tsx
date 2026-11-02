import { Button } from '@/components/ui/button';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { TextField } from '@/components/ui/text-field';
import { RateSourceCards } from '@/features/tenancy/components/RateSourceCards';
import { type SettingsSectionLink, useReportFormState } from '@/features/tenancy/components/SettingsSaveBar';
import { RATE_DECIMALS, typedAmount } from '@/lib/amount-typing';
import { amountInput } from '@/lib/money';
import { bo } from '@/lib/surfaces';
import { type RateSource, type RateView } from '@/types/exchange';
import { useForm } from '@inertiajs/react';
import { type FormEventHandler } from 'react';

interface ExchangeRateFormProps extends SettingsSectionLink {
    source: RateSource;
    manualRate: string;
    sourceOptions: readonly { value: RateSource; label: string }[];
    rateSources: readonly RateView[];
}

interface ExchangeRateFormData {
    [key: string]: string | null;
    exchange_rate_source: string;
    exchange_rate_manual: string | null;
}

export function ExchangeRateForm({ source, manualRate, sourceOptions, rateSources, formId, onStateChange, savesFromBar }: ExchangeRateFormProps) {
    const { data, setData, setDefaults, put, processing, errors, isDirty } = useForm<ExchangeRateFormData>({
        exchange_rate_source: source,
        exchange_rate_manual: manualRate === null ? null : amountInput(manualRate),
    });

    useReportFormState(onStateChange, isDirty, processing);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        put(bo('/ajustes/tasa'), { preserveScroll: true, onSuccess: () => setDefaults() });
    };

    return (
        <form id={formId} className="flex flex-col gap-4" onSubmit={submit} noValidate>
            <RateSourceCards
                value={data.exchange_rate_source}
                onChange={(picked) => setData('exchange_rate_source', picked)}
                sourceOptions={sourceOptions}
                rateSources={rateSources}
                manualRate={manualRate}
                hint="Con esta tasa se proyectan tus precios en bolívares."
                error={errors.exchange_rate_source}
            />

            <div className="sm:max-w-sm">
                <TextField
                    label="Tasa manual (Bs. por USD)"
                    inputMode="decimal"
                    value={data.exchange_rate_manual ?? ''}
                    onChange={(event) => setData('exchange_rate_manual', typedAmount(event, RATE_DECIMALS) || null)}
                    error={errors.exchange_rate_manual}
                    hint={
                        data.exchange_rate_source === 'manual'
                            ? 'Obligatoria: es la tasa con la que vas a vender.'
                            : 'Se guarda aunque no la uses, por si vuelves a «manual».'
                    }
                />
            </div>

            <FormActionBar sticky={false} className={savesFromBar ? 'max-lg:hidden' : undefined}>
                <Button type="submit" loading={processing}>
                    Guardar fuente
                </Button>
            </FormActionBar>
        </form>
    );
}
