import { menuItemClass } from '@/components/shell/user-menu';
import { cilArchive } from '@/components/ui/archive-icon';
import { Icon } from '@/components/ui/icon';
import { useStoredProductPreview } from '@/features/products/components/StoredProductPreview';
import { bo } from '@/lib/surfaces';
import { type ProductListItem } from '@/types/product';
import { cilArrowCircleTop, cilBan, cilOptions, cilPencil, cilReload, cilScreenDesktop } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';
import { Link, router } from '@inertiajs/react';

export function ProductRowActions({ product }: { product: ProductListItem }) {
    const preview = useStoredProductPreview(product.id);
    const step = (path: string) => router.post(bo(`/productos/${product.id}/${path}`), {}, { preserveScroll: true });
    const item = (icon: string[], label: string, path: string) => (
        <MenuItem>
            <button type="button" className={menuItemClass} onClick={() => step(path)}>
                <Icon icon={icon} />
                {label}
            </button>
        </MenuItem>
    );

    return (
        <>
            <Menu as="div" className="relative inline-block">
                <MenuButton className="btn btn-ghost btn-sm btn-square max-md:size-11" aria-label={`Acciones de ${product.name}`}>
                    <Icon icon={cilOptions} />
                </MenuButton>
                <MenuItems
                    anchor="bottom end"
                    className="bg-base-100 border-line shadow-pop z-50 mt-1 w-48 rounded-xl border p-1.5 focus:outline-none"
                >
                    <MenuItem>
                        <Link className={menuItemClass} href={bo(`/productos/${product.id}/editar`)}>
                            <Icon icon={cilPencil} />
                            Editar
                        </Link>
                    </MenuItem>
                    <MenuItem>
                        <button type="button" className={menuItemClass} onClick={preview.open}>
                            <Icon icon={cilScreenDesktop} />
                            Vista previa
                        </button>
                    </MenuItem>
                    {product.status === 'draft' ? item(cilArrowCircleTop, 'Publicar', 'publicar') : null}
                    {product.status === 'published' ? item(cilBan, 'Despublicar', 'despublicar') : null}
                    {product.status === 'archived' ? item(cilReload, 'Restaurar', 'restaurar') : item(cilArchive, 'Archivar', 'archivar')}
                </MenuItems>
            </Menu>
            {preview.dialog}
        </>
    );
}
