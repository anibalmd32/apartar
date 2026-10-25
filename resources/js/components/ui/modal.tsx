import { cn } from '@/lib/utils';
import { cilX } from '@coreui/icons';
import { Description, Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { type ReactNode } from 'react';
import { Icon } from './icon';

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    actions?: ReactNode;
    footnote?: ReactNode;
    size?: 'md' | 'lg';
}

export function Modal({ open, onClose, title, description, children, actions, footnote, size = 'lg' }: ModalProps) {
    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-[rgb(10_13_26/0.55)]" aria-hidden="true" />
            <div className="fixed inset-0 flex items-end justify-center overflow-y-auto sm:items-center sm:p-6">
                <DialogPanel
                    className={cn(
                        'bg-base-100 border-line shadow-pop flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-[18px] border sm:rounded-[18px]',
                        size === 'md' ? 'sm:max-w-[520px]' : 'sm:max-w-[680px]',
                    )}
                >
                    <div className="flex items-start gap-3.5 px-6 pt-[22px] pb-4">
                        <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <DialogTitle className="text-[19px] leading-tight font-bold tracking-[-0.3px]">{title}</DialogTitle>
                            {description ? <Description className="text-ink-3 text-[13px] leading-snug">{description}</Description> : null}
                        </div>
                        <button type="button" onClick={onClose} className="btn btn-ghost btn-sm btn-square" aria-label="Cerrar">
                            <Icon icon={cilX} />
                        </button>
                    </div>

                    <div className="flex flex-col gap-4 overflow-y-auto px-6 pt-1 pb-5">{children}</div>

                    {actions || footnote ? (
                        <div className="bg-surface-2 border-line flex flex-wrap items-center justify-between gap-3 border-t px-6 py-4">
                            <span className="text-ink-3 text-xs">{footnote}</span>
                            <div className="flex flex-wrap items-center gap-2">{actions}</div>
                        </div>
                    ) : null}
                </DialogPanel>
            </div>
        </Dialog>
    );
}
