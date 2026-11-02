import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Icon } from '@/components/ui/icon';
import { Modal } from '@/components/ui/modal';
import { ProductDetailView } from '@/features/catalog/components/ProductDetailView';
import { listOf, missingToPublish, previewContent, previewOffer, type PreviewSource } from '@/features/products/lib/preview';
import { cn } from '@/lib/utils';
import { cilCart, cilScreenDesktop, cilScreenSmartphone } from '@coreui/icons';
import { type ReactNode, useState } from 'react';

type Device = 'mobile' | 'desktop';

const DEVICES: readonly { value: Device; label: string; icon: string[] }[] = [
    { value: 'mobile', label: 'Celular', icon: cilScreenSmartphone },
    { value: 'desktop', label: 'Computadora', icon: cilScreenDesktop },
];

function DeviceToggle({ value, onChange }: { value: Device; onChange: (device: Device) => void }) {
    return (
        <div role="group" aria-label="Ver como en" className="join self-start max-sm:hidden">
            {DEVICES.map((device) => (
                <button
                    key={device.value}
                    type="button"
                    aria-pressed={value === device.value}
                    onClick={() => onChange(device.value)}
                    className={cn('btn btn-sm join-item', value === device.value ? 'btn-primary' : 'btn-ghost border-line')}
                >
                    <Icon icon={device.icon} />
                    {device.label}
                </button>
            ))}
        </div>
    );
}

function PreviewBody({ source, device }: { source: PreviewSource; device: Device }) {
    const missing = missingToPublish(source);

    return (
        <>
            {missing.length > 0 ? (
                <Alert tone="warning">Para publicarlo todavía falta {listOf(missing)}. Así se vería hoy; complétalo antes de publicarlo.</Alert>
            ) : null}

            <div className="bg-base-200 border-line rounded-2xl border p-4 max-sm:p-2">
                <div
                    data-testid="product-preview-frame"
                    data-device={device}
                    className={cn('mx-auto w-full', device === 'mobile' ? 'max-w-[390px]' : 'max-w-none')}
                >
                    <ProductDetailView
                        product={previewContent(source)}
                        offer={previewOffer(source.plan)}
                        reserve={
                            <div className="flex flex-col gap-2">
                                <Button variant="primary" size="lg" block disabled aria-describedby="preview-reserve-note">
                                    <Icon icon={cilCart} />
                                    Apartar ahora
                                </Button>
                                <p id="preview-reserve-note" className="text-ink-2 text-sm">
                                    En la vista previa no se puede apartar: tus clientes lo harán desde aquí.
                                </p>
                            </div>
                        }
                    />
                </div>
            </div>
        </>
    );
}

export function useProductPreview(source: () => PreviewSource): { open: () => void; dialog: ReactNode } {
    const [visible, setVisible] = useState(false);

    const dialog = (
        <PreviewDialog
            open={visible}
            onClose={() => setVisible(false)}
            source={visible ? source() : null}
            description="Con lo que tienes escrito ahora, aunque no lo hayas guardado."
        />
    );

    return { open: () => setVisible(true), dialog };
}

interface PreviewDialogProps {
    open: boolean;
    onClose: () => void;
    source: PreviewSource | null;
    description: string;
    error?: string | null;
}

/**
 * The dialog that shows a product as the public catalog shows it.
 */
export function PreviewDialog({ open, onClose, source, description, error = null }: PreviewDialogProps) {
    const [device, setDevice] = useState<Device>('mobile');

    return (
        <Modal open={open} onClose={onClose} title="Así lo verán tus clientes" description={description} size="xl">
            <DeviceToggle value={device} onChange={setDevice} />
            {error !== null ? <Alert tone="warning">{error}</Alert> : null}
            {open && source !== null ? <PreviewBody source={source} device={device} /> : null}
            {open && source === null && error === null ? (
                <p className="text-ink-3 flex items-center gap-2 py-10 text-sm" role="status">
                    <span className="loading loading-spinner loading-sm" />
                    Cargando la vista previa…
                </p>
            ) : null}
        </Modal>
    );
}
