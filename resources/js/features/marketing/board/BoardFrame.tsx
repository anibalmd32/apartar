import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface BoardFrameProps {
    header: ReactNode;
    footer: ReactNode;
    children: ReactNode;
    className?: string;
}

function MarkerTray() {
    return <div aria-hidden="true" className="bg-pz-frame-deep h-4 rounded-b-[4px] lg:h-5" />;
}

export function BoardFrame({ header, footer, children, className }: BoardFrameProps) {
    return (
        <div className={cn('pizarra bg-pz-wall flex min-h-screen flex-col px-1.5 pt-1.5 sm:px-3 sm:pt-3 lg:px-5 lg:pt-5', className)}>
            <div className="bg-pz-frame mx-auto flex w-full max-w-[1600px] flex-1 flex-col rounded-t-[10px] p-1.5 pb-0 shadow-[inset_0_1px_0_var(--pz-frame-hi),0_6px_14px_-6px_rgb(0_0_0/0.3)] sm:p-2.5 sm:pb-0 lg:p-3 lg:pb-0">
                <div className="pz-board-surface flex flex-1 flex-col rounded-t-[5px] shadow-[inset_0_1px_3px_rgb(0_0_0/0.12)]">
                    {header}
                    <main id="contenido" className="flex-1">
                        {children}
                    </main>
                </div>
                <MarkerTray />
            </div>
            <div className="mx-auto w-full max-w-[1600px]">{footer}</div>
        </div>
    );
}
