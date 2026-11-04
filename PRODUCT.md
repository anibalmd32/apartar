# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

PWA instalable (`public/site.webmanifest`, `public/sw.js`) con notificaciones push. Se usa sobre todo en el navegador del teléfono; sigue siendo web, no nativa.

## Users

Dos públicos que pesan lo mismo en las decisiones de diseño:

- **El vendedor.** Negocio pequeño o vendedor independiente en Venezuela que vende por WhatsApp e Instagram (ropa, accesorios, productos por temporada). Muchas veces trabaja solo o con uno o dos ayudantes del equipo. Usa el panel del negocio para cargar productos, armar catálogos, aprobar comprobantes, llevar la cobranza y hablar con sus clientes.
- **El cliente final.** Persona que recibe el enlace de un catálogo por chat o redes, aparta un producto pagando una inicial y luego paga el resto en cuotas. Entra al portal para ver su plan de pagos y su saldo, y para subir comprobantes. Puede no tener cédula ni teléfono registrados. El portal le pide la cédula al entrar.

Hay un tercer público interno: el equipo de Apartar, que administra la plataforma (negocios, facturas, soporte, auditoría) en `plataforma.apartar.app`.

## Product Purpose

Apartar es un SaaS multi-negocio que reemplaza el catálogo de Instagram más el cuaderno o el Excel de las cuotas. El vendedor publica catálogos por enlace, sus clientes apartan en cuotas, y los dos ven el mismo estado del apartado en todo momento.

Funciona cuando el vendedor sabe quién le debe, cuánto y desde cuándo sin perseguir capturas en el chat, y cuando el cliente entiende cuánto lleva pagado y cuánto le falta sin preguntarle a nadie.

## Positioning

Las cuatro cosas juntas son la razón por la que alguien lo adopta:

1. **Apartado con plan visible.** El cliente ve su plan de cuotas y su saldo desde el primer momento, sin crear cuenta.
2. **USD y Bs. a la tasa del día.** Los precios están en dólares y se muestra su equivalente en bolívares (BCV, paralelo o tasa propia del negocio), calculado a la tasa del día del pago.
3. **Comprobantes en un toque.** El cliente sube el comprobante de pago móvil o transferencia, y el vendedor lo aprueba o lo rechaza sin buscarlo en el chat.
4. **Cobranza ordenada.** Quién debe, cuánto y desde cuándo, agrupado por cliente y con el avance de cada apartado.

Apartar no procesa pagos. Los clientes le pagan directo al negocio, a sus cuentas. Apartar solo le cobra el plan al negocio.

## Operating Context

- **Superficies:**
  - Sitio de marketing y precios.
  - Catálogo público y tienda del negocio, a los que se llega por enlace.
  - Portal del cliente.
  - Panel del negocio en `negocio.apartar.app`.
  - Panel de la plataforma en `plataforma.apartar.app`.
- **Cómo se vende:** el negocio comparte el catálogo por WhatsApp o Instagram. Un catálogo suele corresponder a una campaña, una temporada o una oferta.
- **Cómo se paga:** pago móvil y transferencia bancaria en Venezuela, en bolívares o en dólares. Los fines de semana y feriados usan la tasa del último día hábil. La hora de referencia es la de Caracas.
- **Formato del dinero:** estilo venezolano, con punto de miles y coma decimal (`$4.820,50`, `Bs. 1.234,56`).
- **Herramientas del panel:**
  - Catálogos, productos y taxonomía.
  - Apartados, comprobantes y cobranza.
  - Clientes y chat con clientes.
  - Notificaciones y suscriptores.
  - Analíticas y exportaciones.
  - Cuentas de pago, equipo, auditoría y suscripción del plan.
- **Varios negocios:** una misma persona puede pertenecer a varios negocios y cambiar entre ellos desde el panel.

## Capabilities and Constraints

- **El lado del cliente es una tienda, no un panel administrativo.** El catálogo, la tienda del negocio, la ficha de producto, el flujo de apartar y el portal deben sentirse como entrar en contacto con una tienda. El panel del negocio sí puede verse como herramienta de gestión. Dentro del portal, solo el área donde el cliente edita sus datos y revisa sus registros puede acercarse a una vista de gestión.
- Hay un plan Gratis con el sistema de apartado completo, sin tarjeta y sin período de prueba. Los planes de pago se cobran en dólares cada mes y se pueden pagar con pago móvil o transferencia, también en bolívares. Los nombres, los límites y los precios de los planes viven en los datos, no en el código. No los inventes.
- El cliente puede no tener cédula ni teléfono.
- Toda la interfaz está en español de Venezuela.
- **Sin decidir:** que cada vendedor pueda personalizar más su catálogo o su tienda (marca, apariencia). El dueño lo quiere para más adelante. Por ahora no hay que construirlo, pero el diseño del lado del cliente no debe cerrarle la puerta.

## Brand Commitments

- **Nombre:** Apartar. El verbo "apartar" es la idea central del producto.
- **Dominio:** `apartar.app`.
- **Logo:** `public/logo.svg` y `public/favicon.svg`.
- **Voz que ya usa el producto:**
  - Le habla de tú al usuario.
  - Es cercana y directa.
  - Usa el vocabulario local: pago móvil, Bs., tasa BCV, cédula, inicial, cuotas.
  - Usa frases cortas y concretas, como "Aprueba los pagos", "Sin tarjeta internacional" o "Listo en minutos".
- **El cliente es del vendedor.** En el catálogo y en el portal, el protagonista es el negocio que vende, no Apartar.

## Evidence on Hand

- **Textos reales de marketing y de la sección de precios:** `resources/js/pages/marketing/`.
- **Diseño de referencia del rediseño:** `docs/Apartar.penpot`. Tiene 64 pantallas, cada una en escritorio y móvil, en tema claro y oscuro.
- **Datos de demostración:** se siembran con `php artisan db:reset-demo`, con el negocio de ejemplo "Moda Luna". Son datos ficticios, no son un cliente real.
- **Lo que no existe:** no hay testimonios, logos de clientes, cifras de uso, casos de estudio ni prensa. No los inventes.

## Product Principles

1. **Las dos partes ven la misma verdad.** El saldo, las cuotas y el estado de cada pago se muestran igual al vendedor y al cliente, sin ambigüedad.
2. **Una tienda para el cliente y una herramienta para el vendedor.** Cada lado se diseña para lo que es.
3. **Funciona entre conversaciones.** Las tareas frecuentes (aprobar un comprobante, ver quién debe, compartir un enlace) se resuelven rápido en el teléfono, porque el vendedor está atendiendo el chat.
4. **El dinero es claro en dos monedas.** Cada monto se lee sin hacer cuentas: la moneda está explícita, la tasa es visible y el formato es el venezolano.
5. **Nadie necesita que se lo expliquen.** Usuarios con poca experiencia digital completan los flujos sin ayuda ni términos técnicos.

## Accessibility & Inclusion

- **Teléfonos de gama baja:** Android modestos, pantallas pequeñas y datos caros o lentos. Las páginas deben ser ligeras y no depender de efectos pesados.
- **Poca experiencia digital:** etiquetas claras, objetivos táctiles generosos, estados y errores explicados en lenguaje llano.
- **Tema claro y tema oscuro:** los dos son parte del producto y toda pantalla debe funcionar en ambos.
- **Movimiento reducido:** se respeta `prefers-reduced-motion` (ya está en `resources/css/app.css`).
