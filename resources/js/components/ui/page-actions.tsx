import { menuItemClass } from '@/components/shell/user-menu';
import { buttonClass, type ButtonVariant } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilOptions } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems, MenuSeparator } from '@headlessui/react';
import { Link } from '@inertiajs/react';
import { type HTMLAttributes, type Ref } from 'react';

export const PAGE_HEADER_CLASS = 'group/header relative flex flex-col gap-3.5 lg:flex-row lg:items-end lg:justify-between lg:gap-4';

export const PAGE_TITLE_CLASS = 'flex min-w-0 flex-col gap-1.5 max-lg:group-has-[[data-page-menu]]/header:*:first:pr-12';

export const PAGE_ACTIONS_CLASS = 'flex flex-wrap items-center gap-2 max-lg:has-[[data-page-actions]]:contents';

export interface PageAction {
    label: string;
    icon?: string[];
    href?: string;
    external?: boolean;
    onClick?: () => void;
    opensDialog?: boolean;
    form?: string;
    variant?: ButtonVariant;
    danger?: boolean;
    primary?: boolean;
    mobile?: 'hidden' | 'icon';
    loading?: boolean;
    disabled?: boolean;
    disabledReason?: string;
    elementRef?: Ref<HTMLElement>;
}

function ActionContent({ action }: { action: PageAction }) {
    return (
        <>
            {action.loading ? <span className="loading loading-spinner loading-sm" aria-hidden="true" /> : null}
            {!action.loading && action.icon ? <Icon icon={action.icon} /> : null}
            {action.mobile === 'icon' ? <span className="max-lg:sr-only">{action.label}</span> : action.label}
        </>
    );
}

type ControlProps = { action: PageAction; className: string } & Omit<HTMLAttributes<HTMLElement>, 'className'> & { ref?: Ref<HTMLElement> };

function ActionControl({ action, className, onClick, ...rest }: ControlProps) {
    const forwarded = { ...rest, className, onClick } as Record<string, unknown>;

    if (action.href !== undefined) {
        if (action.external) {
            return (
                <a {...forwarded} href={action.href} target="_blank" rel="noopener noreferrer">
                    <ActionContent action={action} />
                </a>
            );
        }

        if (action.href.startsWith('#')) {
            return (
                <a {...forwarded} href={action.href}>
                    <ActionContent action={action} />
                </a>
            );
        }

        return (
            <Link {...forwarded} href={action.href}>
                <ActionContent action={action} />
            </Link>
        );
    }

    const blocked = action.disabled === true || action.loading === true;

    return (
        <button
            {...forwarded}
            type={action.form === undefined ? 'button' : 'submit'}
            form={action.form}
            onClick={(event) => {
                onClick?.(event);
                action.onClick?.();
            }}
            disabled={blocked}
            aria-busy={action.loading || undefined}
            aria-haspopup={action.opensDialog ? 'dialog' : undefined}
            title={action.disabled ? action.disabledReason : undefined}
        >
            <ActionContent action={action} />
        </button>
    );
}

function OverflowItem({ action }: { action: PageAction }) {
    const className = cn(menuItemClass, 'data-disabled:opacity-50 max-lg:min-h-11', action.danger && 'text-error data-focus:text-error');

    return (
        <MenuItem disabled={action.disabled === true || action.loading === true}>
            <ActionControl action={{ ...action, loading: false }} className={className} />
        </MenuItem>
    );
}

function OverflowMenu({ actions }: { actions: readonly PageAction[] }) {
    const regular = actions.filter((action) => !action.danger);
    const destructive = actions.filter((action) => action.danger);
    const reasons = actions.filter((action) => action.disabled && action.disabledReason);

    return (
        <Menu as="div" data-page-menu className="absolute top-0 right-0 lg:hidden">
            <MenuButton className="btn btn-ghost btn-square size-11" aria-label="Más acciones">
                <Icon icon={cilOptions} size={20} />
            </MenuButton>
            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-1 w-64 rounded-xl border p-1.5 focus:outline-none">
                {regular.map((action) => (
                    <OverflowItem key={action.label} action={action} />
                ))}
                {regular.length > 0 && destructive.length > 0 ? <MenuSeparator className="bg-line my-1 h-px" /> : null}
                {destructive.map((action) => (
                    <OverflowItem key={action.label} action={action} />
                ))}
                {reasons.map((action) => (
                    <p key={action.label} className="text-ink-3 px-2.5 pt-1 pb-1.5 text-xs">
                        {action.disabledReason}
                    </p>
                ))}
            </MenuItems>
        </Menu>
    );
}

export function PageActions({ actions }: { actions: readonly PageAction[] }) {
    const primary = actions.find((action) => action.primary && action.mobile !== 'hidden');
    const overflow = actions.filter((action) => action !== primary && action.mobile === undefined);
    const visible = primary !== undefined || actions.some((action) => action.mobile === 'icon');

    return (
        <>
            <div data-page-actions className={cn('flex flex-wrap items-center gap-2 max-lg:flex-nowrap', !visible && 'max-lg:hidden')}>
                {actions.map((action) => (
                    <ActionControl
                        key={action.label}
                        action={action}
                        ref={action === primary ? action.elementRef : undefined}
                        data-page-primary={action === primary || undefined}
                        aria-label={action.mobile === 'icon' ? action.label : undefined}
                        className={cn(
                            buttonClass(action.variant ?? 'secondary'),
                            action.danger && 'text-error',
                            action === primary
                                ? 'max-lg:btn-primary max-lg:w-full max-lg:flex-1'
                                : action.mobile === 'icon'
                                  ? 'max-lg:btn-square max-lg:order-last max-lg:size-11'
                                  : 'max-lg:hidden',
                        )}
                    />
                ))}
            </div>
            {overflow.length > 0 ? <OverflowMenu actions={overflow} /> : null}
        </>
    );
}
