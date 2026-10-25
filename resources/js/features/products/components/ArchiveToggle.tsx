import { Icon } from '@/components/ui/icon';
import { cilReload, cilTrash } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export function ArchiveToggle({ productId, archived }: { productId: number; archived: boolean }) {
    const [sending, setSending] = useState(false);

    const send = () =>
        router.post(
            `/backoffice/productos/${productId}/${archived ? 'restaurar' : 'archivar'}`,
            {},
            { onStart: () => setSending(true), onFinish: () => setSending(false) },
        );

    return (
        <button type="button" className="btn btn-link btn-sm self-start px-0" onClick={send} disabled={sending}>
            <Icon icon={archived ? cilReload : cilTrash} size={14} />
            {archived ? 'Restaurar producto' : 'Archivar producto'}
        </button>
    );
}
