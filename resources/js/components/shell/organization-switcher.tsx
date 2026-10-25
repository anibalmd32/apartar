import { Avatar } from '@/components/shell/avatar';
import { menuItemClass } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { type TeamOrganization } from '@/types/backoffice';
import { cilCheckAlt, cilChevronBottom } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { router } from '@inertiajs/react';

const ROLES = { owner: 'Dueño', member: 'Equipo' } as const;

export function OrganizationSwitcher({ name, detail, organizations }: { name: string; detail: string; organizations: readonly TeamOrganization[] }) {
    const card = (
        <>
            <Avatar name={name} size={32} tone="accent" />
            <span className="min-w-0 flex-1 text-left">
                <span className="block truncate text-[13px] font-bold">{name}</span>
                {detail ? <span className="text-ink-3 block truncate text-xs">{detail}</span> : null}
            </span>
        </>
    );

    if (organizations.length < 2) {
        return <div className="border-line bg-base-100 flex items-center gap-2.5 rounded-xl border p-2.5">{card}</div>;
    }

    return (
        <Menu as="div" className="relative">
            <MenuButton
                className="border-line bg-base-100 hover:border-line-strong flex w-full cursor-pointer items-center gap-2.5 rounded-xl border p-2.5"
                aria-label={`Negocio: ${name}. Cambiar de negocio`}
            >
                {card}
                <Icon icon={cilChevronBottom} size={14} className="text-ink-3" />
            </MenuButton>
            <MenuItems
                anchor="bottom start"
                className="bg-base-100 border-line shadow-pop z-50 mt-2 w-[228px] rounded-xl border p-1.5 focus:outline-none"
            >
                <p className="text-eyebrow text-ink-3 px-2.5 pt-2 pb-1">Tus negocios</p>
                {organizations.map((organization) => (
                    <MenuItem key={organization.slug}>
                        <button
                            type="button"
                            className={cn(menuItemClass, organization.current && 'font-semibold')}
                            onClick={() => (organization.current ? undefined : router.post(organization.switchUrl))}
                        >
                            <Avatar name={organization.name} size={24} tone="accent" />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate">{organization.name}</span>
                                <span className="text-ink-3 block text-xs">{ROLES[organization.role]}</span>
                            </span>
                            {organization.current ? <Icon icon={cilCheckAlt} size={14} className="text-primary" /> : null}
                        </button>
                    </MenuItem>
                ))}
            </MenuItems>
        </Menu>
    );
}
