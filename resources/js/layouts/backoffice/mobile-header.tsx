import { AttentionBell } from '@/components/shell/attention-bell';
import { Avatar } from '@/components/shell/avatar';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { MobileSearch } from '@/components/shell/mobile-search';
import { type ReactNode } from 'react';

interface MobileHeaderProps {
    organizationName: string;
    pendingReceipts: number | null;
    userMenu: ReactNode;
}

export function MobileHeader({ organizationName, pendingReceipts, userMenu }: MobileHeaderProps) {
    return (
        <header className="bg-base-200 flex h-14 items-center gap-2 px-4 lg:hidden">
            <Avatar name={organizationName} size={34} tone="accent" />
            <p className="line-clamp-2 min-w-0 flex-1 text-[13px] leading-[1.2] font-bold break-words">{organizationName}</p>
            <div className="flex items-center">
                <MobileSearch />
                <AttentionBell pendingReceipts={pendingReceipts} plain />
            </div>
            <CurrencySwitch />
            <div className="ml-1 flex">{userMenu}</div>
        </header>
    );
}
