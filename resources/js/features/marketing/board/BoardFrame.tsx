import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface BoardFrameProps {
    header: ReactNode;
    footer: ReactNode;
    children: ReactNode;
    className?: string;
}

const MARKERS = ['bg-pz-blue', 'bg-pz-ink', 'bg-pz-red', 'bg-pz-green'];

function MarkerTray() {
    return (
        <div
            aria-hidden="true"
            className="relative h-8 rounded-b-[4px] bg-[linear-gradient(var(--pz-frame-hi),var(--pz-frame)_40%,var(--pz-frame-deep))] lg:h-10"
        >
            <div className="absolute inset-x-0 -top-2 flex justify-center gap-5 sm:justify-start sm:gap-8 sm:pl-[10%]">
                {MARKERS.map((marker) => (
                    <span key={marker} className="flex h-3.5 w-20 items-center drop-shadow-[0_2px_1px_rgb(0_0_0/0.25)] sm:w-32">
                        <span className={cn(marker, 'h-3.5 flex-1 rounded-l-full')} />
                        <span className="bg-pz-frame-hi h-2.5 w-4 rounded-r-sm" />
                    </span>
                ))}
            </div>
        </div>
    );
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
