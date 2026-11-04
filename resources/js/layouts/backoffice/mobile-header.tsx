import { AttentionBell } from '@/components/shell/attention-bell';
import { Avatar } from '@/components/shell/avatar';
import { CurrencySwitch } from '@/components/shell/currency-switch';
import { MobileSearch } from '@/components/shell/mobile-search';
import { ThemeToggle } from '@/components/shell/theme-toggle';
import { type ReactNode } from 'react';

interface MobileHeaderProps {
    organizationName: string;
    pendingReceipts: number | null;
    userMenu: ReactNode;
}

export function MobileHeader({ organizationName, pendingReceipts, userMenu }: MobileHeaderProps) {
    return (
        <header className="bg-base-200 flex h-14 items-center gap-1.5 pr-2 pl-3 lg:hidden">
            <Avatar name={organizationName} size={34} tone="accent" />
            <p className="line-clamp-2 min-w-0 flex-1 text-[13px] leading-[1.2] font-bold break-words">{organizationName}</p>
            <div className="flex items-center">
                <MobileSearch />
                <AttentionBell pendingReceipts={pendingReceipts} plain />
                <ThemeToggle />
            </div>
            <CurrencySwitch touch />
            <div className="flex">{userMenu}</div>
        </header>
    );
}
