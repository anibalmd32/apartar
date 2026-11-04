import { ThemeToggle } from '@/components/shell/theme-toggle';
import { Icon } from '@/components/ui/icon';
import { BoardBrand } from '@/features/marketing/board/BoardBrand';
import { SignupCta } from '@/features/marketing/components/SignupCta';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { cilMenu, cilX } from '@coreui/icons';
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/react';
import { Link, usePage } from '@inertiajs/react';

export const HOW_IT_WORKS_ANCHOR = 'como-funciona';

const linkClass =
    'text-pz-ink touch:min-h-11 flex items-center text-[0.9375rem] font-semibold underline-offset-[0.3em] hover:underline aria-[current=page]:underline aria-[current=page]:decoration-pz-blue';

export function SiteHeader() {
    const { auth } = usePage<SharedData>().props;
    const loggedIn = Boolean(auth?.user);
    const current = typeof window === 'undefined' ? '' : window.location.pathname;

    const links = [
        { href: `${route('home')}#${HOW_IT_WORKS_ANCHOR}`, label: 'Cómo funciona', path: null },
        { href: route('pricing'), label: 'Precios', path: '/precios' },
        { href: route('portal.access'), label: 'Soy cliente', path: null },
    ];

    return (
        <header className="relative z-30">
            <a href="#contenido" className="pz-btn pz-btn-blue sr-only focus:not-sr-only focus:absolute focus:top-3 focus:left-3">
                Saltar al contenido
            </a>
            <nav aria-label="Principal" className="mx-auto flex h-16 max-w-[1440px] items-center gap-5 px-4 sm:px-6 lg:h-[84px] lg:gap-8 lg:px-12">
                <Link href={route('home')} className="touch:min-h-11 mr-auto flex items-center" aria-label="Apartar, inicio">
                    <BoardBrand />
                </Link>

                <div className="hidden items-center gap-7 lg:flex">
                    {links.map((link) => (
                        <Link
                            key={link.label}
                            href={link.href}
                            aria-current={link.path !== null && link.path === current ? 'page' : undefined}
                            className={linkClass}
                        >
                            {link.label}
                        </Link>
                    ))}
                </div>

                <ThemeToggle className="text-pz-ink hover:bg-pz-ghost-ink" />

                {loggedIn ? (
                    <Link href={route('dashboard')} className="pz-btn pz-btn-blue">
                        Ir a mi negocio
                    </Link>
                ) : (
                    <div className="flex items-center gap-2 lg:gap-3">
                        <Link href={route('login')} className="pz-btn pz-btn-line touch:min-h-11 hidden min-h-10 px-4 sm:inline-flex">
                            Entrar
                        </Link>
                        <SignupCta className="px-4 max-sm:w-auto sm:px-6" />
                    </div>
                )}

                <Popover className="relative lg:hidden">
                    {({ open }) => (
                        <>
                            <PopoverButton
                                className="text-pz-ink hover:bg-pz-ghost-ink flex size-11 cursor-pointer items-center justify-center rounded-md shadow-[inset_0_0_0_2px_var(--pz-ink)]"
                                aria-label={open ? 'Cerrar el menú' : 'Abrir el menú'}
                            >
                                <Icon icon={open ? cilX : cilMenu} size={20} />
                            </PopoverButton>
                            <PopoverPanel className="bg-pz-board absolute right-0 z-40 mt-2 flex w-60 flex-col rounded-md p-2 shadow-[0_14px_32px_-10px_rgb(0_0_0/0.35),inset_0_0_0_2px_var(--pz-ink)]">
                                {[...links, ...(loggedIn ? [] : [{ href: route('login'), label: 'Entrar a mi negocio', path: null }])].map((link) => (
                                    <Link
                                        key={link.label}
                                        href={link.href}
                                        className={cn(linkClass, 'hover:bg-pz-ghost-ink rounded px-3 hover:no-underline')}
                                    >
                                        {link.label}
                                    </Link>
                                ))}
                            </PopoverPanel>
                        </>
                    )}
                </Popover>
            </nav>
        </header>
    );
}
