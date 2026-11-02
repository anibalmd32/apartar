import { cn } from '@/lib/utils';
import { cilWarning, cilX } from '@coreui/icons';
import { Description, Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import { Children, type FormEventHandler, Fragment, isValidElement, type ReactElement, type ReactNode } from 'react';
import { Icon } from './icon';
import { IconChip } from './icon-chip';

const SIZES = { md: 'sm:max-w-[520px]', lg: 'sm:max-w-[680px]', xl: 'sm:max-w-[1200px]' } as const;

interface ModalProps {
    open: boolean;
    onClose: () => void;
    title: ReactNode;
    description?: ReactNode;
    children: ReactNode;
    actions?: ReactNode;
    footnote?: ReactNode;
    size?: 'md' | 'lg' | 'xl';
    fillActions?: boolean;
    tone?: 'default' | 'danger';
    onSubmit?: FormEventHandler<HTMLFormElement>;
}

export function actionCount(actions: ReactNode): number {
    if (isValidElement(actions) && actions.type === Fragment) {
        return Children.toArray((actions as ReactElement<{ children?: ReactNode }>).props.children).length;
    }

    return Children.toArray(actions).length;
}

function ModalFooter({ actions, footnote, fillActions }: Pick<ModalProps, 'actions' | 'footnote' | 'fillActions'>) {
    const stacked = actionCount(actions) > 2;

    return (
        <div
            data-slot="modal-footer"
            className="bg-surface-2 border-line flex flex-wrap items-center justify-between gap-3 border-t px-6 pt-4 pb-[max(env(safe-area-inset-bottom),1rem)] max-lg:px-4 max-lg:pt-3"
        >
            {footnote ? (
                <span className="text-ink-3 text-xs max-lg:w-full">{footnote}</span>
            ) : !fillActions ? (
                <span className="max-lg:hidden" />
            ) : null}
            <div
                data-fill={fillActions || undefined}
                data-stacked={stacked || undefined}
                className={cn(
                    fillActions ? 'grid w-full auto-cols-fr grid-flow-col gap-3 *:w-full' : 'flex flex-wrap items-center gap-2',
                    stacked
                        ? 'max-lg:flex max-lg:w-full max-lg:flex-col-reverse max-lg:gap-2'
                        : 'max-lg:grid max-lg:w-full max-lg:auto-cols-fr max-lg:grid-flow-col max-lg:gap-3',
                    'max-lg:*:w-full',
                )}
            >
                {actions}
            </div>
        </div>
    );
}

export function Modal({
    open,
    onClose,
    title,
    description,
    children,
    actions,
    footnote,
    size = 'lg',
    fillActions = false,
    tone = 'default',
    onSubmit,
}: ModalProps) {
    const footer = Boolean(actions) || Boolean(footnote);

    return (
        <Dialog open={open} onClose={onClose} className="relative z-50">
            <div className="fixed inset-0 bg-[rgb(10_13_26/0.55)] backdrop-blur-[2px] dark:bg-[rgb(0_0_0/0.72)]" aria-hidden="true" />
            <div className="fixed inset-0 flex items-end justify-center overflow-y-auto sm:items-center sm:p-6">
                <DialogPanel
                    as={onSubmit ? 'form' : 'div'}
                    onSubmit={onSubmit}
                    data-tone={tone}
                    className={cn(
                        'bg-base-100 border-line shadow-pop flex max-h-[92dvh] w-full flex-col overflow-hidden rounded-t-[18px] border sm:rounded-[18px]',
                        SIZES[size],
                    )}
                >
                    <div
                        className={cn(
                            'flex items-start gap-3.5 px-6 pt-[22px] max-lg:items-center max-lg:px-4 max-lg:pt-3',
                            description ? 'pb-1' : 'pb-4',
                        )}
                    >
                        {tone === 'danger' ? <IconChip icon={cilWarning} tone="error" size={40} className="rounded-[12px]" /> : null}
                        <DialogTitle
                            className={cn(
                                'min-w-0 flex-1 text-[19px] leading-tight font-bold tracking-[-0.3px] max-lg:text-[17px]',
                                tone === 'danger' && 'self-center',
                            )}
                        >
                            {title}
                        </DialogTitle>
                        <button type="button" onClick={onClose} className="btn btn-ghost btn-sm btn-square" aria-label="Cerrar">
                            <Icon icon={cilX} />
                        </button>
                    </div>

                    <div
                        data-slot="modal-body"
                        className={cn(
                            'flex flex-col overflow-y-auto overscroll-contain px-6 max-lg:px-4',
                            description ? 'pt-0' : 'pt-1',
                            footer ? 'pb-5' : 'pb-[max(env(safe-area-inset-bottom),1.25rem)]',
                        )}
                    >
                        {description ? (
                            <Description className={cn('text-ink-3 mb-5 text-[13px] leading-snug', tone === 'danger' && 'lg:pl-[54px]')}>
                                {description}
                            </Description>
                        ) : null}
                        <div className="flex flex-col gap-4">{children}</div>
                    </div>

                    {footer ? <ModalFooter actions={actions} footnote={footnote} fillActions={fillActions} /> : null}
                </DialogPanel>
            </div>
        </Dialog>
    );
}
