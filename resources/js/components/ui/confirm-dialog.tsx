import { type Method, type VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { Button } from './button';
import { Modal } from './modal';

interface ConfirmDialogProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    confirmLabel: string;
    tone?: 'danger' | 'primary';
    method?: Method;
    url: string;
    data?: Record<string, string | number | boolean | null>;
    options?: Omit<VisitOptions, 'method' | 'data' | 'onStart' | 'onFinish'>;
    confirmDisabled?: boolean;
    children?: ReactNode;
}

export function ConfirmDialog({
    open,
    onClose,
    title,
    description,
    confirmLabel,
    tone = 'danger',
    method = 'delete',
    url,
    data,
    options,
    confirmDisabled = false,
    children,
}: ConfirmDialogProps) {
    const [processing, setProcessing] = useState(false);

    const confirm = () =>
        router.visit(url, {
            preserveScroll: true,
            ...options,
            method,
            data,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });

    return (
        <Modal
            open={open}
            onClose={processing ? () => undefined : onClose}
            title={title}
            description={description}
            size="md"
            actions={
                <>
                    <Button variant="ghost" onClick={onClose} disabled={processing}>
                        Volver
                    </Button>
                    <Button variant={tone === 'danger' ? 'danger' : 'primary'} onClick={confirm} loading={processing} disabled={confirmDisabled}>
                        {confirmLabel}
                    </Button>
                </>
            }
        >
            {children ?? null}
        </Modal>
    );
}
