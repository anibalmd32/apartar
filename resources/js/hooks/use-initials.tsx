/**
 * Devuelve una función que reduce un nombre completo a sus iniciales.
 *
 * Toma la primera letra del primer nombre y la del último apellido; con una
 * sola palabra devuelve una única inicial, y con una cadena vacía devuelve
 * cadena vacía.
 *
 * @returns función que recibe el nombre completo y devuelve sus iniciales en mayúscula
 */
export function useInitials() {
    const getInitials = (fullName: string): string => {
        const names = fullName.trim().split(' ').filter(Boolean);

        const first = names[0];
        const last = names[names.length - 1];

        if (first === undefined || last === undefined) return '';
        if (names.length === 1) return first.charAt(0).toUpperCase();

        return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
    };

    return getInitials;
}
