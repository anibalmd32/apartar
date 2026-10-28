import type { Editor } from '@tiptap/react';

export const richTextContentClass =
    '[&_li]:mt-1 [&_li_p]:mt-0 [&_ol]:list-decimal [&_ol]:pl-5 [&_p+ol]:mt-2 [&_p+p]:mt-3 [&_p+ul]:mt-2 [&_strong]:font-semibold [&_ol+p]:mt-3 [&_ul]:list-disc [&_ul]:pl-5 [&_ul+p]:mt-3';

/**
 * @returns the HTML of the editor, or an empty string when it has no text.
 */
export function editorHtml(editor: Editor): string {
    return editor.isEmpty ? '' : editor.getHTML();
}

/**
 * @returns how many characters of text the editor holds, without line breaks, as the server counts them.
 */
export function editorTextLength(editor: Editor): number {
    return editor.getText({ blockSeparator: '' }).replace(/\n/g, '').length;
}
