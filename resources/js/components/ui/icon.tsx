import { cn } from '@/lib/utils';

export type CoreIcon = string[];

interface IconProps {
    icon: CoreIcon;
    size?: number;
    label?: string;
    className?: string;
}

export function Icon({ icon, size = 16, label, className }: IconProps) {
    const size2d = icon.length > 1 ? (icon[0] ?? '512 512') : '512 512';
    const body = (icon.length > 1 ? icon[1] : icon[0]) ?? '';
    const viewBox = size2d.split(' ').length === 2 ? `0 0 ${size2d}` : size2d;

    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            viewBox={viewBox}
            width={size}
            height={size}
            fill="currentColor"
            className={cn('shrink-0', className)}
            role={label ? 'img' : undefined}
            aria-label={label}
            aria-hidden={label ? undefined : true}
            focusable="false"
            dangerouslySetInnerHTML={{ __html: body }}
        />
    );
}
