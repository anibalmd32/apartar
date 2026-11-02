import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { router } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

interface LeaveGuardCopy {
    title: string;
    description: string;
}

export function useLeaveGuard(dirty: boolean, copy: LeaveGuardCopy): ReactNode {
    const [pending, setPending] = useState<string | null>(null);
    const leaving = useRef(false);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => {
            if (leaving.current) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', warn);

        const stop = router.on('before', (event) => {
            const { visit } = event.detail;

            if (leaving.current || visit.method !== 'get' || visit.only.length > 0 || visit.prefetch) {
                return;
            }

            event.preventDefault();
            setPending(visit.url.href);
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            stop();
        };
    }, [dirty]);

    const leave = () => {
        const target = pending;

        leaving.current = true;
        setPending(null);

        if (target !== null) {
            router.visit(target, { onFinish: () => (leaving.current = false) });
        }
    };

    return (
        <Modal
            open={pending !== null}
            onClose={() => setPending(null)}
            title={copy.title}
            size="md"
            actions={
                <>
                    <Button variant="ghost" onClick={() => setPending(null)}>
                        Seguir editando
                    </Button>
                    <Button variant="danger" onClick={leave}>
                        Salir sin guardar
                    </Button>
                </>
            }
        >
            <p className="text-ink-2 text-sm">{copy.description}</p>
        </Modal>
    );
}
