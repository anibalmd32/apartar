const ALLOWED = new Set(['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li']);

const DROPPED = new Set(['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript']);

function cleanChildren(source: Node, target: Node, document: Document): void {
    source.childNodes.forEach((child) => {
        if (child.nodeType === Node.TEXT_NODE) {
            target.appendChild(document.createTextNode(child.textContent ?? ''));

            return;
        }

        if (child.nodeType !== Node.ELEMENT_NODE) {
            return;
        }

        const tag = (child as Element).tagName.toLowerCase();

        if (DROPPED.has(tag)) {
            return;
        }

        if (ALLOWED.has(tag)) {
            const copy = document.createElement(tag);
            cleanChildren(child, copy, document);
            target.appendChild(copy);

            return;
        }

        cleanChildren(child, target, document);
    });
}

/**
 * @returns the HTML keeping only the elements the description allows (p, br, strong, em, b, i, ul, ol, li), without attributes.
 */
export function sanitizeRichText(html: string): string {
    const parsed = new DOMParser().parseFromString(html, 'text/html');
    const output = parsed.createElement('div');

    cleanChildren(parsed.body, output, parsed);

    return output.innerHTML;
}

/**
 * @returns the text of the HTML, trimmed, as the server reads it to tell whether a description is empty.
 */
export function plainText(html: string): string {
    return (new DOMParser().parseFromString(html, 'text/html').body.textContent ?? '').trim();
}
