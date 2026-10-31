import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { PhoneField } from '@/components/ui/phone-field';
import { TextField } from '@/components/ui/text-field';
import { type CustomerShowProps } from '@/types/customers';
import { cilPencil, cilTrash } from '@coreui/icons';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';

type EditValues = { full_name: string; phone: string; email: string };

interface ManageCustomerProps {
    customer: CustomerShowProps['customer'];
    manage: CustomerShowProps['manage'];
}

export function ManageCustomer({ customer, manage }: ManageCustomerProps) {
    const [editing, setEditing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const form = useForm<EditValues>({ full_name: customer.name, phone: customer.phone, email: customer.email ?? '' });
    const blocked = manage.liveLayaways > 0;

    const save = () => {
        form.put(manage.updateUrl, { preserveScroll: true, onSuccess: () => setEditing(false) });
    };

    return (
        <>
            <button type="button" className="btn btn-secondary" onClick={() => setEditing(true)}>
                <Icon icon={cilPencil} />
                Editar
            </button>
            <button
                type="button"
                className="btn btn-ghost text-error"
                onClick={() => setDeleting(true)}
                disabled={blocked}
                title={blocked ? 'Tiene apartados en curso: cancélalos o espera a que terminen.' : undefined}
            >
                <Icon icon={cilTrash} />
                Eliminar
            </button>

            <Modal
                open={editing}
                onClose={() => setEditing(false)}
                size="md"
                title="Editar cliente"
                description={`Cédula o RIF ${customer.document}: no se cambia, es lo que identifica la ficha.`}
                actions={
                    <>
                        <button type="button" className="btn btn-ghost" onClick={() => setEditing(false)}>
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
                    <TextField
                        label="Nombre y apellido"
                        name="full_name"
                        value={form.data.full_name}
                        onChange={(event) => form.setData('full_name', event.target.value)}
                        error={form.errors.full_name}
                        required
                    />
                    <PhoneField
                        label="Teléfono celular"
                        name="phone"
                        value={form.data.phone}
                        onChange={(value) => form.setData('phone', value)}
                        error={form.errors.phone}
                        required
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
}
