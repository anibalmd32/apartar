import { cn } from '@/lib/utils';
import { useState, type ReactNode } from 'react';
import { FileStage, type FileKind } from './file-stage';
import { Modal } from './modal';

interface FileLinkProps {
    url: string;
    title: string;
    kind?: FileKind | null;
    className?: string;
    ariaLabel?: string;
    children: ReactNode;
}

export function FileLink({ url, title, kind = null, className, ariaLabel, children }: FileLinkProps) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOpen(true)} aria-label={ariaLabel} className={cn('cursor-pointer text-left', className)}>
                {children}
            </button>
            {open ? (
                <Modal open onClose={() => setOpen(false)} title={title} size="xl">
                    <FileStage key={url} url={url} kind={kind} label={title} />
                </Modal>
            ) : null}
        </>
    );
}
