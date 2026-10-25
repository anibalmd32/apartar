import { Icon } from '@/components/ui/icon';
import { cilSearch } from '@coreui/icons';

export const SEARCH_MAX_LENGTH = 100;

interface SearchInputProps {
    value: string;
    onChange: (text: string) => void;
}

export function SearchInput({ value, onChange }: SearchInputProps) {
    return (
        <label className="input h-10 w-full gap-2.5 px-3">
            <Icon icon={cilSearch} className="text-ink-3" />
            <input
                type="search"
                className="grow"
                value={value}
                maxLength={SEARCH_MAX_LENGTH}
                placeholder="Buscar en este catálogo"
                aria-label="Buscar en este catálogo"
                autoComplete="off"
                onChange={(event) => onChange(event.target.value)}
            />
        </label>
    );
}
