import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { DocumentField } from '@/components/ui/document-field';
import { Modal } from '@/components/ui/modal';
import { type PageAction } from '@/components/ui/page-actions';
import { PhoneField } from '@/components/ui/phone-field';
import { TextField } from '@/components/ui/text-field';
import { type CustomerShowProps } from '@/types/customers';
import { cilPencil, cilTrash } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

type EditValues = { first_name: string; last_name: string; document_number: string; phone: string; email: string };

interface ManageCustomerProps {
    customer: CustomerShowProps['customer'];
    manage: CustomerShowProps['manage'];
}

export function useManageCustomer({ customer, manage }: ManageCustomerProps): { actions: PageAction[]; dialogs: ReactNode } {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm<EditValues>({
        first_name: customer.firstName,
        last_name: customer.lastName ?? '',
        document_number: customer.document ?? '',
        phone: customer.phone ?? '',
        email: customer.email ?? '',
    });
    const blocked = manage.liveLayaways > 0;

    const save = () => {
        form.put(manage.updateUrl, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    const actions: PageAction[] = [
        { label: 'Editar', icon: cilPencil, onClick: () => setEditing(true), variant: 'soft' },
        {
            label: 'Eliminar',
            icon: cilTrash,
            onClick: () => setDeleting(true),
            variant: 'ghost',
            danger: true,
            disabled: blocked,
            disabledReason: 'Tiene apartados en curso: cancélalos o espera a que terminen.',
        },
    ];

    const dialogs = (
        <>
            <Modal
                open={editing}
                onClose={() => setEditing(false)}
                size="md"
                title="Editar cliente"
                actions={
                    <>
                        <button type="button" className="btn" onClick={() => setEditing(false)}>
                            Cancelar
                        </button>
                        <button type="button" className="btn btn-primary" onClick={save} disabled={form.processing}>
                            Guardar
                        </button>
                    </>
                }
            >
                <form
                    className="flex flex-col gap-1"
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                >
                    <div className="grid grid-cols-1 gap-x-3 sm:grid-cols-2">
                        <TextField
                            label="Nombre"
                            name="first_name"
                            value={form.data.first_name}
                            onChange={(event) => form.setData('first_name', event.target.value)}
                            error={form.errors.first_name}
                            required
                        />
                        <TextField
                            label="Apellido (opcional)"
                            name="last_name"
                            value={form.data.last_name}
                            onChange={(event) => form.setData('last_name', event.target.value)}
                            error={form.errors.last_name}
                        />
                    </div>
                    <DocumentField
                        label="Cédula o RIF"
                        name="document_number"
                        value={form.data.document_number}
                        onChange={(value) => form.setData('document_number', value)}
                        error={form.errors.document_number}
                        hint={
                            customer.document === null
                                ? 'Opcional. Si la dejas vacía, el cliente la escribe la primera vez que entre con su código de apartado.'
                                : 'Con ella entra el cliente a ver sus apartados. Cámbiala solo para corregirla.'
                        }
                    />
                    <PhoneField
                        label="Teléfono celular (opcional)"
                        name="phone"
                        value={form.data.phone}
                        onChange={(value) => form.setData('phone', value)}
                        error={form.errors.phone}
                    />
                    <TextField
                        label="Correo (opcional)"
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        error={form.errors.email}
                    />
                </form>
            </Modal>

            <ConfirmDialog
                open={deleting}
                onClose={() => setDeleting(false)}
                title={`¿Eliminar a ${customer.name}?`}
                description="Deja de aparecer en Clientes, en la búsqueda y al crear apartados. Si ya tuvo apartados, su historial se conserva; si vuelve a apartar con la misma cédula, su ficha se reactiva. Su conversación del chat se oculta."
                confirmLabel="Eliminar cliente"
                tone="danger"
                method="delete"
                url={manage.deleteUrl}
            />
        </>
    );

    return { actions, dialogs };
}
