import { Icon } from '@/components/ui/icon';
import { prefersDark, useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
import { cilMoon, cilSun } from '@coreui/icons';

interface ThemeToggleProps {
    className?: string;
    withLabel?: boolean;
}

export function ThemeToggle({ className, withLabel = false }: ThemeToggleProps) {
    const { isDark, updateAppearance } = useAppearance();
    const label = isDark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';

    const toggle = () => {
        const wantsDark = !isDark;

        updateAppearance(wantsDark === prefersDark() ? 'system' : wantsDark ? 'dark' : 'light');
    };

    return (
        <button
            type="button"
            onClick={toggle}
            aria-label={label}
            title={label}
            className={cn(
                'text-base-content hover:bg-surface-2 inline-flex shrink-0 cursor-pointer items-center justify-center rounded-md transition-colors',
                withLabel ? 'touch:min-h-11 min-h-10 gap-2.5 px-3' : 'touch:size-11 size-10',
                className,
            )}
        >
            <Icon icon={isDark ? cilSun : cilMoon} size={18} />
            {withLabel ? <span aria-hidden="true">{isDark ? 'Modo claro' : 'Modo oscuro'}</span> : null}
        </button>
    );
}
