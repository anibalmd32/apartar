export interface CategoryView {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly position: number;
    readonly productsCount: number;
}

export interface TagView {
    readonly id: number;
    readonly name: string;
    readonly slug: string;
    readonly productsCount: number;
}
