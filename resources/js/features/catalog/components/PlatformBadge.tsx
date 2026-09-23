/**
 * El sello «Hecho con Apartar» del plan Free (`REQ-CAT-022` RB-5,
 * `REQ-CAT-021` RB-9).
 *
 * Es la palanca de conversión de la capa gratuita: cada catálogo que un
 * negocio difunde enseña, a quien lo abre, de dónde salió. **Visible pero
 * discreto** —al pie, en letra pequeña— porque el catálogo es del negocio y no
 * nuestro.
 *
 * Quien decide si se pinta es el servidor (`Plan::showsPlatformBadge()`), que
 * es el complemento exacto de la función `branding`: esta pieza no mira el
 * plan.
 */

export function PlatformBadge() {
    return (
        <a href="/registro" className="link link-hover text-xs opacity-70">
            Hecho con Apartar · crea tu catálogo gratis
        </a>
    );
}
