import { type TaxonomyOption } from '@/types/product';

const normalize = (name: string): string => name.normalize('NFD').replace(/\p{M}/gu, '').trim().toLowerCase();

/**
 * @returns the tag selection after adding the suggested tags: known tags are ticked, the rest become new tags.
 */
export function withSuggestedTags(
    suggested: readonly string[],
    known: readonly TaxonomyOption[],
    current: { tagIds: readonly number[]; tagNames: readonly string[] },
): { tagIds: number[]; tagNames: string[] } {
    const tagIds = [...current.tagIds];
    const tagNames = [...current.tagNames];

    for (const tag of suggested) {
        const match = known.find((option) => normalize(option.name) === normalize(tag));

        if (match) {
            if (!tagIds.includes(match.id)) {
                tagIds.push(match.id);
            }

            continue;
        }

        if (!tagNames.some((name) => normalize(name) === normalize(tag))) {
            tagNames.push(tag);
        }
    }

    return { tagIds, tagNames };
}
