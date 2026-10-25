const RIF_PREFIXES = ['J', 'G'];

const DOCUMENT_SEPARATORS = /[\s.\p{Pd}]+/gu;

const PHONE_SEPARATORS = /[\s.()\p{Pd}]+/gu;

export function formatDocumentNumber(raw: string): string {
    const compact = raw.replace(DOCUMENT_SEPARATORS, '').toUpperCase();
    const match = /^([A-Z]?)(\d*)$/.exec(compact);

    if (match === null) {
        return raw;
    }

    const letter = match[1] ?? '';
    const digits = match[2] ?? '';

    if (digits === '') {
        return letter;
    }

    const body = RIF_PREFIXES.includes(letter) ? rifBody(digits) : groupThousands(digits);

    return letter === '' ? body : `${letter}-${body}`;
}

export function formatPhone(raw: string): string {
    const compact = raw.replace(PHONE_SEPARATORS, '');
    const match = /^(\+?)(\d*)$/.exec(compact);

    if (match === null) {
        return raw;
    }

    const plus = match[1] ?? '';
    const digits = match[2] ?? '';

    if (plus !== '') {
        return digits.startsWith('58') && digits.length > 2 ? `+58 ${splitAt(digits.slice(2), 3)}` : `+${digits}`;
    }

    return splitAt(digits, digits.startsWith('0') ? 4 : 3);
}

export function phoneAreaCode(raw: string): string | null {
    const compact = raw.replace(PHONE_SEPARATORS, '');
    const international = compact.startsWith('+');
    const digits = international ? compact.slice(1) : compact;

    if (!/^\d+$/.test(digits)) {
        return null;
    }

    let national = digits;

    if (international || (digits.length === NATIONAL_DIGITS + 2 && digits.startsWith('58'))) {
        if (!digits.startsWith('58')) {
            return null;
        }

        national = digits.slice(2);
    }

    if (national.startsWith('0')) {
        national = national.slice(1);
    }

    return national.length === NATIONAL_DIGITS ? national.slice(0, 3) : null;
}

const NATIONAL_DIGITS = 10;

function groupThousands(digits: string): string {
    const groups: string[] = [];

    for (let end = digits.length; end > 0; end -= 3) {
        groups.unshift(digits.slice(Math.max(0, end - 3), end));
    }

    return groups.join('.');
}

function rifBody(digits: string): string {
    return digits.length > 8 ? `${digits.slice(0, -1)}-${digits.slice(-1)}` : digits;
}

function splitAt(digits: string, position: number): string {
    return digits.length > position ? `${digits.slice(0, position)}-${digits.slice(position)}` : digits;
}
