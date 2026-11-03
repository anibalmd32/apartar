import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { TextField } from '@/components/ui/text-field';
import { ManualPaymentForm } from '@/features/receipts/components/ManualPaymentForm';
import { type ManualPaymentOptions } from '@/types/layaway-backoffice';
import { type PaymentRow } from '@/types/payment-row';
import { type FormEvent, useState } from 'react';

interface MoveTarget {
    layaway: { code: string; customer: string | null; product: string; remaining: string };
    options: ManualPaymentOptions;
}

interface MovePaymentSheetProps {
    payment: PaymentRow | null;
    onClose: () => void;
}

export function MovePaymentSheet({ payment, onClose }: MovePaymentSheetProps) {
    const [code, setCode] = useState('');
    const [target, setTarget] = useState<MoveTarget | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [searching, setSearching] = useState(false);
    const correction = payment?.correction ?? null;

    if (payment === null || correction === null) {
        return null;
    }

    const close = () => {
        setCode('');
        setTarget(null);
        setError(null);
        onClose();
    };

    const search = async (event: FormEvent) => {
        event.preventDefault();
        setSearching(true);
        setError(null);

        try {
            const response = await fetch(`${correction.targetUrl}?${new URLSearchParams({ codigo: code })}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const body = (await response.json()) as MoveTarget & { message?: string };

            if (response.ok) {
                setTarget(body);
            } else {
                setError(body.message ?? 'No pudimos buscar ese apartado.');
            }
        } catch {
            setError('No pudimos buscar ese apartado. Revisa tu conexión.');
        } finally {
            setSearching(false);
        }
    };

    if (target !== null) {
        const { layaway } = target;

        return (
            <ManualPaymentForm
                key={target.options.storeUrl}
                open
                onClose={close}
                title={`Mover a ${layaway.code}`}
                description={`${layaway.customer ?? 'Cliente sin nombre'} · ${layaway.product} · faltan ${layaway.remaining}. Se anula aquí y se registra allá.`}
                options={target.options}
                initial={correction.values}
                submitLabel="Mover el pago"
            />
        );
    }

    return (
        <Modal
            open
            onClose={close}
            onSubmit={search}
            title="Mover a otro apartado"
            description={`Escribe el código del apartado al que corresponde el pago de ${payment.amountUsd.usd}.`}
            actions={
                <>
                    <Button type="button" variant="secondary" onClick={close}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={searching} disabled={code.trim() === ''}>
                        Buscar apartado
                    </Button>
                </>
            }
        >
            <TextField
                label="Código del apartado"
                value={code}
                onChange={(event) => setCode(event.target.value)}
                placeholder="AP-XXXXXXXX"
                autoCapitalize="characters"
                spellCheck={false}
                error={error ?? undefined}
                hint="Lo encuentras en el detalle del apartado o en la ficha del cliente."
            />
        </Modal>
    );
}
