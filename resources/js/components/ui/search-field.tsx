import { Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilSearch } from '@coreui/icons';
import { type InputHTMLAttributes } from 'react';

interface SearchFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'className'> {
    label: string;
    className?: string;
}

export function SearchField({ label, className, type = 'search', ...props }: SearchFieldProps) {
    return (
        <label className={cn('input min-w-0 gap-2.5 px-3 max-lg:flex-1', className)}>
            <Icon icon={cilSearch} className="text-ink-3 shrink-0" />
            <input type={type} enterKeyHint="search" autoComplete="off" aria-label={label} className="min-w-0 grow" {...props} />
        </label>
    );
}
