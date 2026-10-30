import { Modal } from '@/components/ui/modal';
import { NewCustomerFields, type NewCustomerValues } from '@/features/layaway/components/NewCustomerFields';
import { useForm } from '@inertiajs/react';

const EMPTY: NewCustomerValues = { full_name: '', document_number: '', phone: '', email: '', terms_accepted: false };

interface NewCustomerDialogProps {
    open: boolean;
    onClose: () => void;
}

export function NewCustomerDialog({ open, onClose }: NewCustomerDialogProps) {
    const form = useForm<NewCustomerValues>(EMPTY);

    const close = () => {
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        form.post('/backoffice/clientes', { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Modal
            open={open}
            onClose={close}
            size="md"
            title="Nuevo cliente"
            description="Agrega a alguien que te compra por fuera del catálogo. Luego puedes crearle apartados desde su ficha."
            actions={
                <>
                    <button type="button" className="btn btn-ghost" onClick={close}>
                        Cancelar
                    </button>
                    <button type="button" className="btn btn-primary" onClick={submit} disabled={form.processing}>
                        Agregar cliente
                    </button>
                </>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <NewCustomerFields
                    values={form.data}
                    onChange={(patch) => form.setData({ ...form.data, ...patch })}
                    errors={form.errors}
                    documentHint="Una cédula o RIF no puede estar en dos fichas: si ya existe, te lo avisamos."
                    emailHint="Si lo dejas, le llegan sus códigos de apartado y los avisos de pago."
                />
            </form>
        </Modal>
    );
}
