import { Icon } from '@/components/ui/icon';
import { resolvesToDark, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { cilMoon, cilSun } from '@coreui/icons';

interface ThemeToggleProps {
    className?: string;
}

export function ThemeToggle({ className }: ThemeToggleProps) {
    const { appearance, updateAppearance } = useAppearance();
    const dark = resolvesToDark(appearance);
    const label = dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';

    return (
        <button
            type="button"
            onClick={() => updateAppearance(dark ? 'light' : 'dark')}
            aria-label={label}
            title={label}
            className={cn(
                'text-base-content hover:bg-surface-2 touch:size-11 inline-flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors',
                className,
            )}
        >
            <Icon icon={dark ? cilSun : cilMoon} size={18} />
        </button>
    );
}
