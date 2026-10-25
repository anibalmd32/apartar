import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { SelectField } from '@/components/ui/select-field';
import { type CategoryView } from '@/types/taxonomy';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface DeleteCategoryDialogProps {
    category: CategoryView;
    others: readonly CategoryView[];
    open: boolean;
    onClose: () => void;
}

export function DeleteCategoryDialog({ category, others, open, onClose }: DeleteCategoryDialogProps) {
    const url = `/backoffice/categorias/${category.id}`;
    const [target, setTarget] = useState('');
    const [sending, setSending] = useState(false);

    const reassign = (andDelete: boolean) => {
        if (target === '') {
            return;
        }

        setSending(true);
        router.post(
            `${url}/reasignar`,
            { category_id: Number(target) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTarget('');
                    onClose();

                    if (andDelete) {
                        router.delete(url, { preserveScroll: true });
                    }
                },
                onFinish: () => setSending(false),
            },
        );
    };

    const count = category.productsCount === 1 ? '1 producto' : `${category.productsCount} productos`;

    return (
        <Modal
            open={open}
            onClose={onClose}
            size="md"
            title={`Eliminar «${category.name}»`}
            description={`Esta categoría tiene ${count}. Elige a cuál moverlos antes de eliminarla.`}
            actions={
                <>
                    <Button variant="secondary" onClick={onClose}>
                        Cancelar
                    </Button>
                    <Button variant="ghost" onClick={() => reassign(false)} disabled={target === '' || sending}>
                        Solo mover
                    </Button>
                    <Button variant="danger" onClick={() => reassign(true)} disabled={target === ''} loading={sending}>
                        Mover y eliminar
                    </Button>
                </>
            }
        >
            <SelectField
                label="Mover productos a"
                name={`reassign-${category.id}`}
                options={[{ value: '', label: 'Elige una categoría' }, ...others.map((other) => ({ value: String(other.id), label: other.name }))]}
                value={target}
                onChange={(event) => setTarget(event.target.value)}
            />
        </Modal>
    );
}
