import { type SelectOption } from '@/components/ui/select-field';

export const TIMEZONE_OPTIONS: readonly SelectOption[] = [
    { value: 'America/Caracas', label: 'Hora de Caracas' },
    { value: 'America/Bogota', label: 'Hora de Bogotá' },
    { value: 'America/Panama', label: 'Hora de Panamá' },
    { value: 'America/Lima', label: 'Hora de Lima' },
    { value: 'America/Santo_Domingo', label: 'Hora de Santo Domingo' },
    { value: 'America/New_York', label: 'Hora de Miami y Nueva York' },
    { value: 'America/Santiago', label: 'Hora de Santiago de Chile' },
    { value: 'America/Argentina/Buenos_Aires', label: 'Hora de Buenos Aires' },
    { value: 'Europe/Madrid', label: 'Hora de Madrid' },
];

export function timezoneOptions(current: string): readonly SelectOption[] {
    if (current === '' || TIMEZONE_OPTIONS.some((option) => option.value === current)) {
        return TIMEZONE_OPTIONS;
    }

    return [...TIMEZONE_OPTIONS, { value: current, label: current.replace(/_/g, ' ') }];
}
