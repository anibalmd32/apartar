import { menuItemClass } from '@/components/shell/user-menu';
import { Icon } from '@/components/ui/icon';
import { type PaymentRow } from '@/types/receipt';
import { cilArrowRight, cilBan, cilOptions, cilPencil } from '@coreui/icons';
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/react';

export type PaymentAction = 'void' | 'edit' | 'move';

interface PaymentRowActionsProps {
    payment: PaymentRow;
    onAction: (payment: PaymentRow, action: PaymentAction) => void;
    canCorrect?: boolean;
}

export function PaymentRowActions({ payment, onAction, canCorrect = false }: PaymentRowActionsProps) {
    const correctable = canCorrect && payment.correction !== null && payment.correction !== undefined;

    return (
        <Menu as="div" className="relative inline-block">
            <MenuButton className="btn btn-ghost btn-sm btn-square -my-1" aria-label={`Acciones del pago de ${payment.amountUsd.usd}`}>
                <Icon icon={cilOptions} />
            </MenuButton>
            <MenuItems anchor="bottom end" className="bg-base-100 border-line shadow-pop z-50 mt-1 w-56 rounded-xl border p-1.5 focus:outline-none">
                {correctable ? (
                    <>
                        <MenuItem>
                            <button type="button" className={menuItemClass} onClick={() => onAction(payment, 'edit')}>
                                <Icon icon={cilPencil} />
                                Editar pago
                            </button>
                        </MenuItem>
                        <MenuItem>
                            <button type="button" className={menuItemClass} onClick={() => onAction(payment, 'move')}>
                                <Icon icon={cilArrowRight} />
                                Mover a otro apartado
                            </button>
                        </MenuItem>
                    </>
                ) : null}
                <MenuItem>
                    <button type="button" className={`${menuItemClass} text-error data-focus:text-error`} onClick={() => onAction(payment, 'void')}>
                        <Icon icon={cilBan} />
                        Anular pago
                    </button>
                </MenuItem>
            </MenuItems>
        </Menu>
    );
}
