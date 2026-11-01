import { type ChangeEvent } from 'react';

export const AMOUNT_DECIMALS = 2;

export const RATE_DECIMALS = 8;

const groupThousands = (digits: string): string => digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');

export function formatAmountTyping(raw: string, decimals: number = AMOUNT_DECIMALS): string {
    const comma = raw.indexOf(',');
    const typedDot = comma === -1 && raw.endsWith('.') ? raw.length - 1 : -1;
    const split = comma !== -1 ? comma : typedDot;
    const wholeRaw = split === -1 ? raw : raw.slice(0, split);
    const decimalRaw = split === -1 ? '' : raw.slice(split + 1);

    const digits = wholeRaw.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const whole = digits === '' && split !== -1 ? '0' : groupThousands(digits);

    if (split === -1 || decimals === 0) {
        return whole;
    }

    return `${whole},${decimalRaw.replace(/\D/g, '').slice(0, decimals)}`;
}

function significantBefore(value: string, position: number): number {
    return value.slice(0, position).replace(/[^\d,]/g, '').length;
}

function positionAfter(value: string, significant: number): number {
    if (significant === 0) {
        return 0;
    }

    let seen = 0;

    for (let index = 0; index < value.length; index++) {
        if (/[\d,]/.test(value.charAt(index))) {
            seen++;
        }

        if (seen === significant) {
            return index + 1;
        }
    }

    return value.length;
}

export function typedAmount(event: ChangeEvent<HTMLInputElement>, decimals: number = AMOUNT_DECIMALS): string {
    const input = event.target;
    const raw = input.value;
    const formatted = formatAmountTyping(raw, decimals);
    const caret = input.selectionStart;

    if (caret !== null && caret < raw.length) {
        const target = positionAfter(formatted, significantBefore(raw.replace(/\.$/, ','), caret));

        window.requestAnimationFrame(() => {
            if (document.activeElement === input) {
                input.setSelectionRange(target, target);
            }
        });
    }

    return formatted;
}
