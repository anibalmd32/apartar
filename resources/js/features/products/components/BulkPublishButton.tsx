import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { cilArrowCircleTop } from '@coreui/icons';
import { router } from '@inertiajs/react';
import { useState } from 'react';

export const BULK_PUBLISH_URL = '/backoffice/productos/publicar-varios';

export function BulkPublishButton({ ids, onDone }: { ids: readonly number[]; onDone: () => void }) {
    const [sending, setSending] = useState(false);

    const publish = () =>
        router.post(
            BULK_PUBLISH_URL,
            { ids: [...ids] },
            {
                preserveScroll: true,
                onStart: () => setSending(true),
                onSuccess: onDone,
                onFinish: () => setSending(false),
            },
        );

    return (
        <Button size="sm" onClick={publish} loading={sending} disabled={ids.length === 0}>
            {sending ? null : <Icon icon={cilArrowCircleTop} />}
            Publicar
        </Button>
    );
}
