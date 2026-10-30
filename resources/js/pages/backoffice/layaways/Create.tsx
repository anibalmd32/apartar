import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CheckboxField } from '@/components/ui/checkbox-field';
import { FilterTabs } from '@/components/ui/filter-tabs';
import { SectionCard } from '@/components/ui/section-card';
import { SelectField } from '@/components/ui/select-field';
import { BackofficePlanCard } from '@/features/layaway/components/BackofficePlanCard';
import { CustomerPicker } from '@/features/layaway/components/CustomerPicker';
import { DownPaymentWindowFields } from '@/features/layaway/components/DownPaymentWindowFields';
import { NewCustomerFields, type NewCustomerValues } from '@/features/layaway/components/NewCustomerFields';
import { QuantityStepper } from '@/features/layaway/components/QuantityStepper';
import { PaymentFields } from '@/features/receipts/components/PaymentFields';
import { useApprovalPreview } from '@/features/receipts/hooks/useApprovalPreview';
import { emptyPaymentValues, type PaymentFieldValues, paymentPending } from '@/features/receipts/lib/payment-fields';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { type BackofficeLayawayCreateProps, type DownPaymentWindowChoice, type LayawayCustomerOption } from '@/types/layaway-backoffice';
import { router, useForm } from '@inertiajs/react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

export const PLAN_DEBOUNCE_MS = 300;

type CustomerMode = 'existing' | 'new';

interface CreateLayawayValues extends PaymentFieldValues, NewCustomerValues {
    [key: string]: string | number | boolean | File | null;
    product: string;
    quantity: number;
    customer_mode: CustomerMode;
    customer: string;
    window: DownPaymentWindowChoice;
    window_value: string;
    with_payment: boolean;
    idempotency_key: string;
}

export default function LayawayCreate({ products, selection, plan, customer, window: limits, payment, urls }: BackofficeLayawayCreateProps) {
    const [picked, setPicked] = useState<LayawayCustomerOption | null>(customer);

    const form = useForm<CreateLayawayValues>({
        ...emptyPaymentValues(payment),
        product: selection.product === null ? '' : String(selection.product),
        quantity: selection.quantity,
        customer_mode: 'existing',
        customer: customer === null ? '' : String(customer.id),
        full_name: '',
        document_number: '',
        phone: '',
        email: '',
        terms_accepted: false,
        window: 'default',
        window_value: '',
        with_payment: false,
        idempotency_key: crypto.randomUUID(),
    });

    const { data, errors } = form;
    const product = products.find((option) => String(option.id) === data.product) ?? null;
    const maxQuantity = product?.maxQuantity ?? 1;
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = window.setTimeout(() => {
            router.reload({ only: ['plan', 'selection'], data: { producto: data.product, cantidad: data.quantity } });
        }, PLAN_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [data.product, data.quantity]);

    const previewState = useApprovalPreview(
        `${urls.paymentPreview}?${new URLSearchParams({ product: data.product, quantity: String(data.quantity) }).toString()}`,
        { amount: data.with_payment ? data.amount : '', currency: data.currency, rate: data.rate },
        { amount: '', currency: 'USD', rate: '', preview: null, error: null },
        errors.confirm_stale_rate ?? '',
    );

    const set = (patch: Partial<CreateLayawayValues>) => form.setData((current) => ({ ...current, ...patch }) as CreateLayawayValues);

    const chooseProduct = (value: string) => {
        const next = products.find((option) => String(option.id) === value);

        set({ product: value, quantity: Math.min(data.quantity, next?.maxQuantity ?? 1) });
    };

    const chooseCustomer = (option: LayawayCustomerOption | null) => {
        setPicked(option);
        set({ customer: option === null ? '' : String(option.id) });
    };

    const customerMissing = data.customer_mode === 'existing' ? data.customer === '' : data.full_name.trim() === '' || !data.terms_accepted;
    const windowApplies = plan === null || plan.requiresDownPayment;
    const windowMissing = windowApplies && (data.window === 'hours' || data.window === 'days') && !(Number(data.window_value) > 0);
    const blocked =
        form.processing || product === null || customerMissing || windowMissing || (data.with_payment && paymentPending(payment, data, previewState));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => (windowApplies ? values : { ...values, window: 'default', window_value: '' }));
        form.post(urls.store, { forceFormData: true, preserveScroll: true });
    };

    return (
        <BackofficeLayout
            title="Nuevo apartado"
            back={{ href: '/backoffice/cobranza', label: 'Cobranza' }}
            description="Aparta un producto a nombre de un cliente, con las mismas condiciones de tu catálogo."
        >
            <form onSubmit={submit} noValidate aria-label="Nuevo apartado" className="flex flex-col gap-4">
                {errors.plan ? <Alert tone="warning">{errors.plan}</Alert> : null}
                {errors.idempotency_key ? <Alert tone="error">{errors.idempotency_key}</Alert> : null}

                <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px] lg:gap-6">
                    <div className="flex min-w-0 flex-col gap-4">
                        <SectionCard title="Producto" subtitle="Precio, inicial, cuotas y frecuencia salen del producto.">
                            {products.length === 0 ? (
                                <Alert tone="info">
                                    No tienes productos publicados con existencias. Publica uno o súbele el stock para apartarlo.
                                </Alert>
                            ) : (
                                <SelectField
                                    label="Producto"
                                    name="product"
                                    options={[
                                        { value: '', label: 'Elige el producto' },
                                        ...products.map((option) => ({
                                            value: String(option.id),
                                            label: `${option.name} · ${option.sku} (${option.stock} en stock)`,
                                        })),
                                    ]}
                                    value={data.product}
                                    onChange={(event) => chooseProduct(event.target.value)}
                                    error={errors.product}
                                />
                            )}
                            {product !== null ? (
                                <QuantityStepper
                                    value={data.quantity}
                                    max={maxQuantity}
                                    onChange={(value) =>
                                        set({ quantity: Number.isFinite(value) ? Math.min(Math.max(Math.trunc(value), 1), maxQuantity) : 1 })
                                    }
                                    error={errors.quantity}
                                    hint={maxQuantity > 1 ? `Hasta ${maxQuantity} unidades.` : 'Se aparta una unidad.'}
                                />
                            ) : null}
                        </SectionCard>

                        <SectionCard title="Cliente">
                            <FilterTabs<CustomerMode>
                                label="Tipo de cliente"
                                tabs={[
                                    { value: 'existing', label: 'Ya es cliente' },
                                    { value: 'new', label: 'Cliente nuevo' },
                                ]}
                                value={data.customer_mode}
                                onChange={(value) => set({ customer_mode: value })}
                            />
                            {data.customer_mode === 'existing' ? (
                                <CustomerPicker searchUrl={urls.customers} selected={picked} onChange={chooseCustomer} error={errors.customer} />
                            ) : (
                                <NewCustomerFields values={data} onChange={(patch) => set(patch)} errors={errors} />
                            )}
                        </SectionCard>

                        {windowApplies ? (
                            <DownPaymentWindowFields
                                choice={data.window}
                                value={data.window_value}
                                onChange={(choice, value) => set({ window: choice, window_value: value })}
                                limits={limits}
                                errors={errors}
                            />
                        ) : null}

                        <SectionCard title="Pago">
                            <CheckboxField
                                label="Registrar un pago ahora"
                                name="with_payment"
                                checked={data.with_payment}
                                onChange={(event) => set({ with_payment: event.target.checked })}
                                hint={
                                    plan === null || plan.requiresDownPayment
                                        ? 'Tiene que cubrir la inicial: el apartado nace activo con sus cuotas.'
                                        : 'Se aplica a las cuotas en orden.'
                                }
                            />
                            {data.with_payment ? (
                                <PaymentFields
                                    values={data}
                                    errors={errors}
                                    onChange={(patch) => set(patch)}
                                    options={payment}
                                    preview={previewState}
                                />
                            ) : null}
                        </SectionCard>
                    </div>

                    <div className="flex flex-col gap-4 lg:sticky lg:top-4">
                        <BackofficePlanCard plan={plan} />
                        <Button type="submit" variant="primary" block loading={form.processing} disabled={blocked}>
                            Crear el apartado
                        </Button>
                    </div>
                </div>
            </form>
        </BackofficeLayout>
    );
}
