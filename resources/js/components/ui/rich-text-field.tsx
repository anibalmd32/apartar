import { RichTextToolbar } from '@/components/ui/rich-text-toolbar';
import { editorHtml, editorTextLength, richTextContentClass } from '@/lib/rich-text';
import { cn } from '@/lib/utils';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { type ReactNode, useEffect, useId, useMemo, useRef, useState } from 'react';

interface RichTextFieldProps {
    label: string;
    value: string;
    onChange: (html: string) => void;
    error?: string;
    hint?: ReactNode;
    maxLength?: number;
    id?: string;
    disabled?: boolean;
    className?: string;
}

const extensions = [
    StarterKit.configure({
        blockquote: false,
        code: false,
        codeBlock: false,
        heading: false,
        horizontalRule: false,
        link: false,
        strike: false,
        trailingNode: false,
        underline: false,
    }),
];

/**
 * Controlled rich text editor: `value` is HTML and changing it from outside replaces the content.
 */
export function RichTextField({ label, value, onChange, error, hint, maxLength, id, disabled = false, className }: RichTextFieldProps) {
    const generatedId = useId();
    const fieldId = id ?? generatedId;
    const labelId = `${fieldId}-label`;
    const hintId = `${fieldId}-hint`;
    const errorId = `${fieldId}-error`;
    const describedBy = [hint || maxLength ? hintId : null, error ? errorId : null].filter(Boolean).join(' ');

    const onChangeRef = useRef(onChange);
    const [length, setLength] = useState(0);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    const editorProps = useMemo(
        () => ({
            attributes: {
                id: fieldId,
                role: 'textbox',
                'aria-multiline': 'true',
                'aria-labelledby': labelId,
                ...(describedBy === '' ? {} : { 'aria-describedby': describedBy }),
                ...(error ? { 'aria-invalid': 'true' } : {}),
                class: cn('min-h-28 px-3 py-2 text-sm leading-relaxed outline-none', richTextContentClass),
            },
        }),
        [fieldId, labelId, describedBy, error],
    );

    const editor = useEditor({
        extensions,
        content: value,
        editable: !disabled,
        immediatelyRender: false,
        editorProps,
        onCreate: ({ editor: created }) => setLength(editorTextLength(created)),
        onUpdate: ({ editor: updated }) => {
            setLength(editorTextLength(updated));
            onChangeRef.current(editorHtml(updated));
        },
    });

    useEffect(() => {
        if (editor === null || editor.isDestroyed || value === editorHtml(editor)) {
            return;
        }

        editor.commands.setContent(value, { emitUpdate: false });
        setLength(editorTextLength(editor));
    }, [editor, value]);

    useEffect(() => {
        if (editor !== null && editor.isEditable === disabled) {
            editor.setEditable(!disabled, false);
        }
    }, [editor, disabled]);

    const overLimit = maxLength !== undefined && length > maxLength;

    return (
        <fieldset className={cn('fieldset w-full', className)}>
            <span id={labelId} className="fieldset-legend cursor-default" onClick={() => editor?.commands.focus()}>
                {label}
            </span>

            <div
                className={cn(
                    'textarea flex h-auto w-full flex-col p-0',
                    (error || overLimit) && 'textarea-error',
                    disabled && 'cursor-not-allowed opacity-60',
                )}
            >
                <RichTextToolbar editor={editor} disabled={disabled} />
                <EditorContent editor={editor} className="w-full" />
            </div>

            {hint || maxLength ? (
                <p id={hintId} className="label flex justify-between gap-3 text-xs whitespace-normal">
                    <span>{hint}</span>
                    {maxLength === undefined ? null : (
                        <span className={cn('shrink-0 tabular-nums', overLimit && 'text-error')}>
                            {length} / {maxLength}
                        </span>
                    )}
                </p>
            ) : null}

            {error ? (
                <p id={errorId} role="alert" className="label text-error text-xs whitespace-normal">
                    {error}
                </p>
            ) : null}
        </fieldset>
    );
}
