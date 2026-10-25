import { Icon } from '@/components/ui/icon';
import { type Appearance, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { cilAccountLogout, cilCheckAlt, cilContrast, cilMoon, cilSun } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems, MenuSeparator } from '@headlessui/react';
import { type ReactNode } from 'react';
import { Avatar } from './avatar';

interface UserMenuProps {
    name: string;
    detail?: string;
    onLogout: () => void;
    extra?: ReactNode;
    className?: string;
}

const THEMES: { value: Appearance; label: string; icon: string[] }[] = [
    { value: 'light', label: 'Claro', icon: cilSun },
    { value: 'dark', label: 'Oscuro', icon: cilMoon },
    { value: 'system', label: 'Como el sistema', icon: cilContrast },
];

export const menuItemClass =
    'data-focus:bg-surface-2 flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-sm text-ink-2 data-focus:text-base-content';

export function UserMenu({ name, detail, onLogout, extra, className }: UserMenuProps) {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <Menu as="div" className={cn('relative', className)}>
            <MenuButton
                className="focus-visible:outline-primary rounded-full focus-visible:outline-2 focus-visible:outline-offset-2"
                aria-label={`Menú de ${name}`}
            >
                <Avatar name={name} size={36} />
            </MenuButton>

            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-2 w-64 rounded-xl border p-1.5 focus:outline-none">
                <div className="flex items-center gap-3 px-2.5 py-2">
                    <Avatar name={name} size={36} />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-bold">{name}</p>
                        {detail ? <p className="text-ink-3 truncate text-xs">{detail}</p> : null}
                    </div>
                </div>

                <MenuSeparator className="bg-line my-1 h-px" />

                {extra}

                <p className="text-eyebrow text-ink-3 px-2.5 pt-2 pb-1">Tema</p>
                {THEMES.map((theme) => (
                    <MenuItem key={theme.value}>
                        <button type="button" className={menuItemClass} onClick={() => updateAppearance(theme.value)}>
                            <Icon icon={theme.icon} />
                            <span className="flex-1">{theme.label}</span>
                            {appearance === theme.value ? <Icon icon={cilCheckAlt} className="text-primary" label="activo" /> : null}
                        </button>
                    </MenuItem>
                ))}

                <MenuSeparator className="bg-line my-1 h-px" />

                <MenuItem>
                    <button type="button" className={menuItemClass} onClick={onLogout}>
                        <Icon icon={cilAccountLogout} />
                        Salir
                    </button>
                </MenuItem>
            </MenuItems>
        </Menu>
    );
}
