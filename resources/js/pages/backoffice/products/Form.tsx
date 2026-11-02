import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { FormActionBar } from '@/components/ui/form-action-bar';
import { Icon } from '@/components/ui/icon';
import { PageActions } from '@/components/ui/page-actions';
import { ArchiveToggle } from '@/features/products/components/ArchiveToggle';
import { PendingImagePicker } from '@/features/products/components/PendingImagePicker';
import { ProductCopyAssistant } from '@/features/products/components/ProductCopyAssistant';
import { ProductIdentityFields } from '@/features/products/components/ProductIdentityFields';
import { ProductImageGallery } from '@/features/products/components/ProductImageGallery';
import { ProductInventoryFields } from '@/features/products/components/ProductInventoryFields';
import { useProductPreview } from '@/features/products/components/ProductPreview';
import { ProductPricingFields } from '@/features/products/components/ProductPricingFields';
import { ProductTaxonomyFields } from '@/features/products/components/ProductTaxonomyFields';
import { SpecificationBuilder } from '@/features/products/components/SpecificationBuilder';
import { useGalleryDraft } from '@/features/products/hooks/useGalleryDraft';
import { usePendingImages } from '@/features/products/hooks/usePendingImages';
import { useProductForm } from '@/features/products/hooks/useProductForm';
import { useSpecificationRows } from '@/features/products/hooks/useSpecificationRows';
import { type PreviewSource } from '@/features/products/lib/preview';
import { withSuggestedTags } from '@/features/products/lib/tags';
import { useLeaveGuard } from '@/hooks/use-leave-guard';
import { useSubmitDock } from '@/hooks/use-submit-dock';
import BackofficeLayout from '@/layouts/backoffice-layout';
import { formatDateTime } from '@/lib/dates';
import { bo } from '@/lib/surfaces';
import {
    type AiAvailability,
    type FinancingPlanView,
    type ProductDefaults,
    type ProductFormValues,
    type ProductStatus,
    type TaxonomyOption,
} from '@/types/product';
import { cilCheckAlt, cilExternalLink, cilScreenDesktop } from '@coreui/icons';
import { type FormEventHandler } from 'react';

interface ProductFormPageProps {
    product: ProductFormValues | null;
    defaults: ProductDefaults;
    categories: TaxonomyOption[];
    tags: TaxonomyOption[];
    plan: FinancingPlanView | null;
    ai: AiAvailability;
    [key: string]: unknown;
}

const STATUS_BADGES: Readonly<Record<ProductStatus, { label: string; tone: 'neutral' | 'success' }>> = {
    draft: { label: 'Borrador', tone: 'neutral' },
    published: { label: 'Publicado', tone: 'success' },
    archived: { label: 'Archivado', tone: 'neutral' },
};

const FORM_ID = 'product-form';

export default function ProductForm({ product, defaults, categories, tags, plan, ai }: ProductFormPageProps) {
    const { data, setData, errors, processing, submit, isDirty } = useProductForm(defaults, product);

    const sheet = useSpecificationRows(product);

    const photos = usePendingImages(defaults.maxImages ?? 10);

    const gallery = useGalleryDraft(product?.images ?? []);

    const fields = { data, setData, errors };

    const categoryName = categories.find((category) => String(category.id) === data.category_id)?.name ?? data.category_name;

    const assistant = (
        <ProductCopyAssistant
            ai={ai}
            input={{ name: data.name, categoryName, description: data.description, photos: photos.files, productId: product?.id ?? null }}
            hasStoredPhotos={(product?.images.length ?? 0) > 0}
            onApply={(description, characteristics, suggestedTags) => {
                const selection = withSuggestedTags(suggestedTags, tags, { tagIds: data.tag_ids, tagNames: data.tag_names });

                setData((previous) => ({ ...previous, description, tag_ids: selection.tagIds, tag_names: selection.tagNames }));
                sheet.append(characteristics);
            }}
        />
    );

    const previewSource = (): PreviewSource => ({
        name: data.name,
        sku: data.sku,
        description: data.description,
        stock: data.stock ?? 0,
        categoryName,
        specifications: sheet.payload(),
        images:
            product === null
                ? photos.images.map((image) => ({ thumb: image.src, card: image.src, zoom: image.src, alt: data.name }))
                : product.images.map((image) => image.set),
        plan,
        fewLeft: defaults.fewLeft,
    });

    const preview = useProductPreview(previewSource);

    const dirty =
        isDirty || gallery.dirty || photos.files.length > 0 || JSON.stringify(sheet.payload()) !== JSON.stringify(product?.specifications ?? []);

    const leaveGuard = useLeaveGuard(dirty && !processing, {
        title: '¿Salir sin guardar?',
        description: 'Tienes cambios en este producto que todavía no guardaste. Si sales ahora, se pierden.',
    });

    const dock = useSubmitDock(product === null || dirty);

    const submitLabel = product === null ? 'Crear producto' : 'Guardar cambios';

    const onSubmit: FormEventHandler = (event) => {
        event.preventDefault();

        submit(sheet.payload(), product === null ? photos.files : [], gallery.dirty ? gallery.order : null);
    };

    const status = product === null ? null : STATUS_BADGES[product.status];

    return (
        <BackofficeLayout
            title={product === null ? 'Nuevo producto' : product.name}
            description="Lo que vendes y con qué condiciones lo financias. Tus clientes verán estas cifras tal como quedan aquí."
            back={{ href: bo('/productos'), label: 'Productos' }}
            badge={status === null ? undefined : <Badge tone={status.tone}>{status.label}</Badge>}
            actions={
                <PageActions
                    actions={[
                        ...(product?.publicUrl
                            ? [
                                  {
                                      label: 'Ver en catálogo',
                                      icon: cilExternalLink,
                                      href: product.publicUrl,
                                      external: true,
                                      variant: 'ghost' as const,
                                  },
                              ]
                            : []),
                        { label: 'Vista previa', icon: cilScreenDesktop, onClick: preview.open, opensDialog: true, variant: 'ghost' },
                        { label: 'Cancelar', href: bo('/productos'), mobile: 'hidden' },
                        {
                            label: submitLabel,
                            icon: cilCheckAlt,
                            form: FORM_ID,
                            loading: processing,
                            variant: 'primary',
                            primary: true,
                            elementRef: dock.headerRef,
                        },
                    ]}
                />
            }
        >
            {product?.status === 'archived' ? (
                <Alert tone="warning">
                    Este producto está archivado: no se muestra en tus catálogos, pero conserva su sitio en ellos y su historial.
                </Alert>
            ) : null}

            <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px] lg:gap-6">
                <form id={FORM_ID} className="contents" onSubmit={onSubmit} noValidate>
                    <div
                        className={`flex flex-col gap-4 max-lg:order-2 lg:col-start-1 lg:gap-6 ${product === null ? 'lg:row-start-1' : 'lg:row-start-2'}`}
                    >
                        {product === null ? <PendingImagePicker photos={photos} errors={errors} /> : null}
                        <ProductIdentityFields {...fields} product={product} skuSequence={defaults.skuSequence} descriptionActions={assistant} />
                    </div>

                    <div
                        className={`flex flex-col gap-4 max-lg:order-4 lg:col-start-1 lg:gap-6 ${product === null ? 'lg:row-start-2' : 'lg:row-start-3'}`}
                    >
                        <SpecificationBuilder rows={sheet} errors={errors} />
                    </div>

                    <div
                        className={`flex flex-col gap-4 max-lg:order-3 lg:col-start-1 lg:gap-6 ${product === null ? 'lg:row-start-3' : 'lg:row-start-4'}`}
                    >
                        <ProductPricingFields {...fields} plan={plan} />
                    </div>

                    <div className="flex flex-col gap-4 max-lg:order-5 lg:col-start-2 lg:row-span-4 lg:row-start-1 lg:gap-6">
                        <ProductTaxonomyFields {...fields} categories={categories} tags={tags} />
                        <ProductInventoryFields {...fields} saved={product} />

                        {product?.updatedAt ? <p className="text-ink-3 text-xs">Última modificación: {formatDateTime(product.updatedAt)}</p> : null}

                        {product !== null ? <ArchiveToggle productId={product.id} archived={product.status === 'archived'} /> : null}
                    </div>

                    <FormActionBar sticky={dock.sticky} className="order-6 max-lg:-mx-4 max-lg:px-4 lg:hidden">
                        <Button type="submit" loading={processing}>
                            <Icon icon={cilCheckAlt} />
                            {submitLabel}
                        </Button>
                    </FormActionBar>
                </form>

                {product === null ? null : (
                    <div className="max-lg:order-1 lg:col-start-1 lg:row-start-1">
                        <ProductImageGallery
                            productId={product.id}
                            images={product.images}
                            maxImages={product.maxImages}
                            errors={errors}
                            gallery={gallery}
                        />
                    </div>
                )}
            </div>
            {preview.dialog}
            {leaveGuard}
        </BackofficeLayout>
    );
}
