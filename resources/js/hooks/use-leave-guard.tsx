import { Button } from '@/components/ui/button';
import { Modal } from '@/components/ui/modal';
import { onHistoryPop } from '@/lib/history-guard';
import { router } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

interface LeaveGuardCopy {
    title: string;
    description: string;
}

type PendingLeave = { kind: 'visit'; url: string } | { kind: 'back' };

function samePage(href: string): boolean {
    const target = new URL(href, window.location.href);

    return target.pathname === window.location.pathname && target.search === window.location.search;
}

export function useLeaveGuard(dirty: boolean, copy: LeaveGuardCopy): ReactNode {
    const [pending, setPending] = useState<PendingLeave | null>(null);
    const leaving = useRef(false);

    useEffect(() => {
        if (!dirty) {
            return;
        }

        leaving.current = false;

        const guarded = { url: window.location.href, state: window.history.state as unknown };

        const warn = (event: BeforeUnloadEvent) => {
            if (leaving.current) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        };

        const back = () => {
            if (leaving.current) {
                return false;
            }

            if (samePage(guarded.url)) {
                document.getElementById(decodeURIComponent(window.location.hash.slice(1)))?.scrollIntoView();

                return true;
            }

            window.history.pushState(guarded.state, '', guarded.url);
            setPending({ kind: 'back' });

            return true;
        };

        window.addEventListener('beforeunload', warn);
        const unguard = onHistoryPop(back);

        const stop = router.on('before', (event) => {
            const { visit } = event.detail;

            if (leaving.current || visit.method !== 'get' || visit.only.length > 0 || visit.prefetch) {
                return;
            }

            event.preventDefault();
            setPending({ kind: 'visit', url: visit.url.href });
        });

        return () => {
            window.removeEventListener('beforeunload', warn);
            unguard();
            stop();
        };
    }, [dirty]);

    const leave = () => {
        const target = pending;

        leaving.current = true;
        setPending(null);

        if (target?.kind === 'visit') {
            router.visit(target.url, { onFinish: () => (leaving.current = false) });
        }

        if (target?.kind === 'back') {
            window.history.back();
        }
    };

    return (
        <Modal
            open={pending !== null}
            onClose={() => setPending(null)}
            title={copy.title}
            size="md"
            tone="danger"
            actions={
                <>
                    <Button variant="secondary" onClick={() => setPending(null)}>
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
