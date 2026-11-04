import { cn } from '@/lib/utils';

export function BoardBrand({ className }: { className?: string }) {
    return (
        <span className={cn('font-display text-pz-blue text-[1.875rem] leading-none font-black [font-stretch:72%] lg:text-[2.125rem]', className)}>
            apartar
        </span>
    );
}
