import { Button } from '@/components/ui/button';
import { FileField } from '@/components/ui/file-field';
import { FileLink } from '@/components/ui/file-link';
import { Modal } from '@/components/ui/modal';
import { SelectField } from '@/components/ui/select-field';
import { TextField } from '@/components/ui/text-field';
import { type SafeguardOptions, type SafeguardView } from '@/types/safeguard';
import { router, useForm } from '@inertiajs/react';
import { type FormEventHandler, useId } from 'react';

interface SafeguardSheetProps {
    safeguard: SafeguardView;
    options: SafeguardOptions;
    amount: string;
    onClose: () => void;
}

export function SafeguardSheet({ safeguard, options, amount, onClose }: SafeguardSheetProps) {
    const record = safeguard.record;
    const noteId = useId();
    const form = useForm({
        destination: record?.destination ?? '',
        reference: record?.reference ?? '',
        note: record?.note ?? '',
        proof: null as File | null,
    });

    const submit: FormEventHandler = (event) => {
        event.preventDefault();
        form.post(safeguard.storeUrl, { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    const remove = () => {
        if (safeguard.destroyUrl !== null) {
            router.delete(safeguard.destroyUrl, { preserveScroll: true, onSuccess: onClose });
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            onSubmit={submit}
            title={record === null ? 'Registrar el resguardo' : 'Editar el resguardo'}
            description={`Dónde quedó el pago de ${amount}${safeguard.equivalent !== null ? `, que equivale a ${safeguard.equivalent} en paralelo` : ''}. Todo es opcional menos dónde.`}
            actions={
                <>
                    {record !== null ? (
                        <Button type="button" variant="ghost" onClick={remove} disabled={form.processing}>
                            Borrar resguardo
                        </Button>
                    ) : null}
                    <Button type="button" variant="secondary" onClick={onClose} disabled={form.processing}>
                        Cancelar
                    </Button>
                    <Button type="submit" loading={form.processing} disabled={form.data.destination === ''}>
                        Guardar
                    </Button>
                </>
            }
        >
            <div className="flex flex-col gap-1">
                <SelectField
                    label="Dónde lo resguardas"
                    name="destination"
                    options={[{ value: '', label: 'Elige dónde' }, ...options.destinations]}
                    value={form.data.destination}
                    onChange={(event) => form.setData('destination', event.target.value)}
                    error={form.errors.destination}
                    required
                />
                <TextField
                    label="Número de referencia (opcional)"
                    name="reference"
                    value={form.data.reference}
                    onChange={(event) => form.setData('reference', event.target.value)}
                    error={form.errors.reference}
                    autoComplete="off"
                />
                <FileField
                    label="Comprobante (opcional)"
                    name="proof"
                    accept={options.file.accept}
                    onChange={(event) => form.setData('proof', event.target.files?.[0] ?? null)}
                    error={form.errors.proof}
                    hint={
                        record?.proofUrl ? (
                            <FileLink url={record.proofUrl} title="Comprobante del resguardo" className="link link-primary">
                                Ver el comprobante guardado
                            </FileLink>
                        ) : (
                            `Captura o PDF de hasta ${options.file.maxMb} MB.`
                        )
                    }
                />
                <label className="fieldset-legend" htmlFor={noteId}>
                    Nota (opcional)
                </label>
                <textarea
                    id={noteId}
                    name="note"
                    className="textarea w-full"
                    rows={2}
                    maxLength={500}
                    value={form.data.note}
                    onChange={(event) => form.setData('note', event.target.value)}
                />
                {form.errors.note !== undefined ? <span className="text-error text-sm">{form.errors.note}</span> : null}
            </div>
        </Modal>
    );
}
