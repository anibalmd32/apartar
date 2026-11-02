export function whatsappChatUrl(phone: string): string | null {
    const digits = phone.replace(/\D/g, '');
    const national = digits.startsWith('58') ? digits.slice(2) : digits.replace(/^0/, '');

    return /^4\d{9}$/.test(national) ? `https://wa.me/58${national}` : null;
}
