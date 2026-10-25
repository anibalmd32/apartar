import { Brand } from '@/components/shell/brand';
import { Icon } from '@/components/ui/icon';
import { type SharedData } from '@/types';
import { cilMenu, cilX } from '@coreui/icons';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';

export const HOW_IT_WORKS_ANCHOR = 'como-funciona';

const linkClass = 'text-ink-2 hover:text-base-content text-[15px] font-medium transition-colors';

export function SiteHeader() {
    const { auth } = usePage<SharedData>().props;
    const loggedIn = Boolean(auth?.user);

    const links = [
        { href: `${route('home')}#${HOW_IT_WORKS_ANCHOR}`, label: 'Cómo funciona' },
        { href: route('pricing'), label: 'Precios' },
        { href: route('portal.access'), label: 'Para clientes' },
    ];

    return (
        <header className="bg-base-200">
            <nav aria-label="Principal" className="mx-auto flex h-[60px] max-w-[1440px] items-center gap-6 px-4 lg:h-[76px] lg:px-20">
                <Link href={route('home')} className="mr-auto" aria-label="Apartar, inicio">
                    <Brand />
                </Link>

                <div className="hidden items-center gap-6 lg:flex">
                    {links.map((link) => (
                        <Link key={link.label} href={link.href} className={linkClass}>
                            {link.label}
                        </Link>
                    ))}
                </div>

                {loggedIn ? (
                    <Link href={route('dashboard')} className="btn btn-primary btn-sm rounded-full lg:h-10 lg:px-4 lg:text-sm">
                        Ir al backoffice
                    </Link>
                ) : (
                    <div className="flex items-center gap-2 lg:gap-4">
                        <Link href={route('login')} className="btn btn-ghost btn-sm lg:h-10 lg:px-4 lg:text-sm">
                            Entrar
                        </Link>
                        <Link href={route('register')} className="btn btn-primary hidden h-10 rounded-full px-4 text-sm lg:inline-flex">
                            Empezar gratis
                        </Link>
                    </div>
                )}

                <Popover className="relative lg:hidden">
                    {({ open }) => (
                        <>
                            <PopoverButton
                                className="border-line hover:bg-surface-2 flex size-9 cursor-pointer items-center justify-center rounded-[10px] border"
                                aria-label={open ? 'Cerrar el menú' : 'Abrir el menú'}
                            >
                                <Icon icon={open ? cilX : cilMenu} size={18} />
                            </PopoverButton>
                            <PopoverPanel className="border-line bg-base-100 shadow-pop absolute right-0 z-40 mt-2 flex w-56 flex-col gap-1 rounded-2xl border p-2">
                                {links.map((link) => (
                                    <Link key={link.label} href={link.href} className="hover:bg-surface-2 rounded-lg px-3 py-2.5 text-sm font-medium">
                                        {link.label}
                                    </Link>
                                ))}
                                {loggedIn ? null : (
                                    <Link href={route('register')} className="btn btn-primary btn-sm mt-1 rounded-full">
                                        Empezar gratis
                                    </Link>
                                )}
                            </PopoverPanel>
                        </>
                    )}
                </Popover>
            </nav>
        </header>
    );
}
