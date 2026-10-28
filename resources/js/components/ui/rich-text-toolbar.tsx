import { type CoreIcon, Icon } from '@/components/ui/icon';
import { cn } from '@/lib/utils';
import { cilActionRedo, cilActionUndo, cilBold, cilItalic, cilList, cilListNumbered } from '@coreui/icons';
import { type Editor, useEditorState } from '@tiptap/react';

interface ToolbarAction {
    label: string;
    icon: CoreIcon;
    run: (editor: Editor) => boolean;
    active?: 'bold' | 'italic' | 'bulletList' | 'orderedList';
    enabledBy?: 'canUndo' | 'canRedo';
}

const FORMAT_ACTIONS: ToolbarAction[] = [
    { label: 'Negrita', icon: cilBold, active: 'bold', run: (editor) => editor.chain().focus().toggleBold().run() },
    { label: 'Cursiva', icon: cilItalic, active: 'italic', run: (editor) => editor.chain().focus().toggleItalic().run() },
    { label: 'Lista con viñetas', icon: cilList, active: 'bulletList', run: (editor) => editor.chain().focus().toggleBulletList().run() },
    {
        label: 'Lista numerada',
        icon: cilListNumbered,
        active: 'orderedList',
        run: (editor) => editor.chain().focus().toggleOrderedList().run(),
    },
];

const HISTORY_ACTIONS: ToolbarAction[] = [
    { label: 'Deshacer', icon: cilActionUndo, enabledBy: 'canUndo', run: (editor) => editor.chain().focus().undo().run() },
    { label: 'Rehacer', icon: cilActionRedo, enabledBy: 'canRedo', run: (editor) => editor.chain().focus().redo().run() },
];

interface RichTextToolbarProps {
    editor: Editor | null;
    disabled?: boolean;
}

export function RichTextToolbar({ editor, disabled = false }: RichTextToolbarProps) {
    const state = useEditorState({
        editor,
        selector: ({ editor: current }) => ({
            bold: current?.isActive('bold') ?? false,
            italic: current?.isActive('italic') ?? false,
            bulletList: current?.isActive('bulletList') ?? false,
            orderedList: current?.isActive('orderedList') ?? false,
            canUndo: current?.can().undo() ?? false,
            canRedo: current?.can().redo() ?? false,
        }),
    });

    const button = (action: ToolbarAction) => {
        const pressed = action.active === undefined ? undefined : Boolean(state?.[action.active]);
        const unavailable = disabled || editor === null || (action.enabledBy !== undefined && !state?.[action.enabledBy]);

        return (
            <button
                key={action.label}
                type="button"
                className={cn('btn btn-ghost btn-xs btn-square', pressed && 'btn-active text-primary')}
                aria-label={action.label}
                title={action.label}
                aria-pressed={pressed}
                disabled={unavailable}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => {
                    if (editor !== null) {
                        action.run(editor);
                    }
                }}
            >
                <Icon icon={action.icon} size={14} />
            </button>
        );
    };

    return (
        <div role="toolbar" aria-label="Formato del texto" className="border-base-300 flex items-center gap-0.5 border-b px-1.5 py-1">
            {FORMAT_ACTIONS.map(button)}
            <span aria-hidden="true" className="bg-base-300 mx-1 h-4 w-px" />
            {HISTORY_ACTIONS.map(button)}
        </div>
    );
}
