import { menuItemClass } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { type PaymentRow } from '@/types/receipt';
import { cilBan, cilOptions } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';

interface PaymentRowActionsProps {
    payment: PaymentRow;
    onVoid: (payment: PaymentRow) => void;
}

export function PaymentRowActions({ payment, onVoid }: PaymentRowActionsProps) {
    return (
        <Menu as="div" className="relative inline-block">
            <MenuButton className="btn btn-ghost btn-sm btn-square -my-1" aria-label={`Acciones del pago de ${payment.amountUsd.usd}`}>
                <Icon icon={cilOptions} />
            </MenuButton>
            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-1 w-48 rounded-xl border p-1.5 focus:outline-none">
                <MenuItem>
                    <button type="button" className={`${menuItemClass} text-error data-focus:text-error`} onClick={() => onVoid(payment)}>
                        <Icon icon={cilBan} />
                        Anular pago
                    </button>
                </MenuItem>
            </MenuItems>
        </Menu>
    );
}
