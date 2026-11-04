---
name: Apartar
description: Catálogos con apartado y cuotas para negocios pequeños de Venezuela, escritos en la pizarra del día.
colors:
  marker-blue: "#1c45c4"
  marker-blue-deep: "#15379f"
  on-blue: "#ffffff"
  marker-red: "#c21d27"
  marker-green: "#0d6b41"
  review-amber: "#8a5a0b"
  ink: "#15171b"
  ink-2: "#4a4f58"
  ink-3: "#5f646d"
  wall: "#dde0e3"
  board: "#f4f5f2"
  board-hi: "#fbfcfa"
  frame: "#b6bbc1"
  frame-deep: "#8f959c"
  frame-hi: "#e8ebed"
  rule: "rgb(21 23 27 / 0.2)"
  rule-soft: "rgb(21 23 27 / 0.1)"
  ghost-blue: "rgb(28 69 196 / 0.09)"
  ghost-ink: "rgb(21 23 27 / 0.07)"
  night-blue: "#86a0ff"
  night-blue-deep: "#a5b8ff"
  on-night-blue: "#0b0d12"
  night-red: "#ff7479"
  night-green: "#55d996"
  night-amber: "#f0c060"
  chalk-ink: "#f0f1ed"
  night-ink-2: "#b8bcc3"
  night-ink-3: "#9aa0a8"
  night-wall: "#08090b"
  night-board: "#111316"
  night-board-hi: "#16181c"
  night-frame: "#3b4048"
  night-frame-deep: "#24282e"
  night-frame-hi: "#555b64"
  night-rule: "rgb(240 241 237 / 0.22)"
  night-rule-soft: "rgb(240 241 237 / 0.1)"
  night-ghost-blue: "rgb(134 160 255 / 0.12)"
  night-ghost-ink: "rgb(240 241 237 / 0.08)"
typography:
  display:
    fontFamily: "Archivo, 'Archivo Vinyl Fallback', 'Archivo Fallback', ui-sans-serif, sans-serif"
    fontSize: "clamp(3.25rem, 1.6rem + 6.4vw, 6rem)"
    fontWeight: 850
    lineHeight: 0.9
    letterSpacing: "-0.012em"
    fontVariation: "'wdth' 68"
  headline:
    fontFamily: "Archivo, 'Archivo Vinyl Fallback', 'Archivo Fallback', ui-sans-serif, sans-serif"
    fontSize: "4.5rem"
    fontWeight: 850
    lineHeight: 0.9
    letterSpacing: "-0.012em"
    fontVariation: "'wdth' 68"
  figure:
    fontFamily: "Archivo, 'Archivo Vinyl Fallback', 'Archivo Fallback', ui-sans-serif, sans-serif"
    fontSize: "4.5rem"
    fontWeight: 850
    lineHeight: 0.9
    letterSpacing: "-0.012em"
    fontFeature: "'tnum' 1"
    fontVariation: "'wdth' 68"
  title:
    fontFamily: "Archivo, 'Archivo Fallback', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 800
    lineHeight: 1.33
    fontVariation: "'wdth' 85"
  body:
    fontFamily: "Archivo, 'Archivo Fallback', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 400
    lineHeight: 1.625
    fontFeature: "'tnum' 1"
  body-panel:
    fontFamily: "Archivo, 'Archivo Fallback', ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.4
    letterSpacing: "0em"
    fontFeature: "'tnum' 1"
  annotation:
    fontFamily: "'Gochi Hand', Archivo, cursive"
    fontSize: "1.75rem"
    fontWeight: 400
    lineHeight: 1.375
  label:
    fontFamily: "Archivo, 'Archivo Fallback', ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 750
    lineHeight: 1
    letterSpacing: "0.005em"
    fontVariation: "'wdth' 92"
  brand:
    fontFamily: "Archivo, 'Archivo Fallback', ui-sans-serif, system-ui, sans-serif"
    fontSize: "2.125rem"
    fontWeight: 900
    lineHeight: 1
    letterSpacing: "-0.01em"
    fontVariation: "'wdth' 72"
rounded:
  tray: "4px"
  board: "5px"
  field: "6px"
  box: "8px"
  frame: "10px"
  pill: "9999px"
spacing:
  frame-sm: "6px"
  frame-md: "10px"
  frame-lg: "12px"
  row-tight: "6px"
  row: "12px"
  gutter-sm: "16px"
  gutter-md: "24px"
  gutter-lg: "48px"
  section: "64px"
  section-lg: "96px"
components:
  button-primary:
    backgroundColor: "{colors.marker-blue}"
    textColor: "{colors.on-blue}"
    typography: "{typography.label}"
    rounded: "{rounded.field}"
    padding: "0 24px"
    height: "48px"
  button-primary-hover:
    backgroundColor: "{colors.marker-blue-deep}"
    textColor: "{colors.on-blue}"
  button-line:
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.field}"
    padding: "0 24px"
    height: "48px"
  button-line-hover:
    backgroundColor: "{colors.ghost-ink}"
    textColor: "{colors.ink}"
  chip-installment:
    textColor: "{colors.ink}"
    rounded: "{rounded.pill}"
    padding: "0 16px"
    height: "40px"
  chip-installment-hover:
    backgroundColor: "{colors.ghost-ink}"
  chip-installment-selected:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.board}"
  board-surface:
    backgroundColor: "{colors.board}"
    textColor: "{colors.ink}"
    rounded: "{rounded.board}"
  ledger-row:
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    padding: "12px 0"
  progress-segment-paid:
    backgroundColor: "{colors.marker-green}"
    rounded: "{rounded.pill}"
    height: "14px"
  input-field:
    backgroundColor: "{colors.board-hi}"
    textColor: "{colors.ink}"
    rounded: "{rounded.field}"
    height: "44px"
---

# Design System: Apartar

## Overview

**Creative North Star: "La pizarra del día"**

Apartar es la pizarra del mostrador que se reescribe cada mañana: la tasa de hoy, el precio en dos monedas y la cuenta de lo que resta, a la vista del vendedor y del cliente. Todo el sistema sale de ese objeto. En claro es un acrílico blanco frío con marco y bandeja de aluminio; en oscuro, una pizarra negra de marcador líquido. Sobre ella se escribe con pocos marcadores, cada uno con un solo papel, y lo de ayer queda como rastro borrado debajo de lo de hoy.

La densidad es la de un mostrador, no la de un folleto: titulares de vinilo adhesivo enormes, renglones punteados donde se alinean nombres y montos, cifras tabulares que cuadran en columna, y anotaciones breves a mano que dicen el día, la tasa o «ejemplo». No hay tarjetas, ni iconos en cajas, ni sombras de adorno; la jerarquía la ponen el tamaño, el marcador y el renglón. El tiempo ordena la lectura: hoy arriba, las fechas como eje.

El mundo pertenece a Apartar. Vive en el sitio público (inicio, precios, registro, legales) y, en modo herramienta, en el panel del negocio. El lado del cliente (tienda, catálogo, ficha de producto, flujo de apartar y portal) no es pizarra: allí el protagonista es el negocio que vende, y Apartar solo firma el plan de cuotas con este lenguaje (renglones, roles de color, cifras tabulares). Este documento no diseña esas pantallas.

**Key Characteristics:**
- Pizarra a todo el ancho dentro de un marco de aluminio con bandeja de marcadores al pie.
- Cinco papeles de color fijos: azul acción, rojo lo que resta, verde lo pagado, ámbar en revisión, tinta el texto.
- Titulares en grotesca condensada de vinilo, en mayúsculas, interlineado 0.9.
- Letra a mano solo para anotaciones; Archivo para todo lo que se lee o se toca.
- Renglones punteados en lugar de tarjetas; cifras tabulares siempre.
- Movimiento de marcador (escribir, borrar, encerrar) solo cuando la cuenta se reescribe.

## Colors

Una superficie neutra fría y pocos marcadores saturados, cada uno con un único significado que no se presta a otro uso.

### Primary
- **Marcador azul** (`marker-blue`; oscuro `night-blue`): lo que dice Apartar y la acción. Botón principal «Empezar gratis», la marca, la tasa del día, la palabra que se destaca en un titular («inicial», «la misma»), los ✓ de beneficios, los rótulos de tiempo de los pasos, el anillo de foco, el cursor de escritura y la selección de texto.
- **Marcador azul hondo** (`marker-blue-deep`; oscuro `night-blue-deep`): solo el hover del botón azul y su labio inferior de 1px.
- **Sobre azul** (`on-blue`; oscuro `on-night-blue`): texto sobre el marcador azul.

### Secondary
- **Marcador rojo** (`marker-red`; oscuro `night-red`): solo lo que resta y lo atrasado. «Resta» encerrado en círculo rojo, el saldo pendiente del cliente, «cuota vencida hace 3 días».
- **Marcador verde** (`marker-green`; oscuro `night-green`): solo lo pagado. El ✓ de una cuota pagada, el monto pagado, los segmentos llenos de la barra de avance, «pagado completo».

### Tertiary
- **Marcador ámbar** (`review-amber`; oscuro `night-amber`): en revisión (un comprobante por aprobar). Hoy vive solo como el color `warning` de los temas daisyUI; la pizarra aún no tiene una variable `--pz-*` propia para él.

### Neutral
- **Tinta** (`ink`; oscuro `chalk-ink`): todo el texto principal, los titulares de vinilo, el borde de 2px de los botones de línea y el fondo del chip seleccionado.
- **Tinta media** (`ink-2`; oscuro `night-ink-2`): párrafos de explicación y etiquetas de datos.
- **Tinta tenue** (`ink-3`; oscuro `night-ink-3`): anotaciones secundarias, cuotas pagadas tachadas, el rastro de ayer, notas de fuente.
- **Pared** (`wall`; oscuro `night-wall`): la pared detrás del marco; se ve en los bordes y bajo el pie.
- **Pizarra** (`board`, `board-hi`; oscuro `night-board`, `night-board-hi`): la superficie de escritura, con un brillo diagonal entre las dos (`--pz-sheen`, 115°). `board-hi` es también el fondo de campos y cajas.
- **Marco de aluminio** (`frame`, `frame-deep`, `frame-hi`; oscuro `night-frame*`): el marco, la bandeja de marcadores (`frame-deep`) y el canto iluminado del marco (`frame-hi`).
- **Renglón** (`rule`, `rule-soft`; oscuro `night-rule*`): los punteados de cada renglón, los separadores entre secciones (2px, `rule-soft`), la línea de tiempo y los segmentos vacíos de la barra de avance.
- **Fantasma** (`ghost-ink`, `ghost-blue`; oscuro `night-ghost-*`): el hover de controles de línea y el fondo suave de lo azul; nunca un fondo de sección.

### Named Rules
**The One Marker, One Job Rule.** Cada marcador tiene un solo papel: azul es acción y lo que dice Apartar; rojo es solo lo que resta y lo atrasado; verde es solo lo pagado; ámbar es solo lo que está en revisión; la tinta es el texto. Un éxito que no es un pago no va en verde; un error de formulario no reemplaza al rojo de «resta» como lenguaje de la cuenta; un adorno nunca se pinta con un marcador.

**The Acrylic and Blackboard Rule.** Claro es acrílico blanco frío; oscuro es pizarra negra. Los dos temas son el mismo objeto con otro material, no una inversión de colores: cada token `--pz-*` tiene su pareja en `[data-theme='apartar-dark']` y toda pantalla debe funcionar en ambos.

## Typography

**Display Font:** Archivo variable condensado al 68% de ancho, peso 850 (con 'Archivo Vinyl Fallback': Arial Narrow o Roboto Condensed ajustados)
**Body Font:** Archivo (con 'Archivo Fallback': Arial, Helvetica Neue o Roboto)
**Label/Mono Font:** Gochi Hand para anotaciones a mano (con Archivo)

Las tres caras son autoalojadas (`/fonts/archivo-latin.woff2`, `archivo-latin-ext.woff2`, `gochi-hand-latin.woff2`) con `font-display: swap` y precarga de las dos principales.

**Character:** Una sola familia hace dos voces: el vinilo condensado en mayúsculas que se pega en la pizarra y la grotesca de trabajo que explica y opera. La mano de Gochi Hand es el marcador del vendedor: breve, inclinada a veces, siempre al margen de lo que se lee.

### Hierarchy
- **Display** (850, `clamp(3.25rem, 1.6rem + 6.4vw, 6rem)`, 0.9): el titular de la primera pantalla de cada página, en mayúsculas con `text-balance`. Una palabra puede ir en marcador azul.
- **Headline** (850, de 2.75rem a 4.5rem, 0.9): títulos de sección, también vinilo en mayúsculas, con ancho máximo de 12 a 16ch.
- **Figure** (850, de 3.5rem a 4.5rem, 0.9, tabular): la cifra que manda, como el precio del ejemplo o el precio de un plan.
- **Title** (800, 1.5rem, ancho 85%): títulos de paso y subtítulos de documento; preguntas frecuentes a 1.25rem, 700, ancho 90%.
- **Body** (400, 1.125rem a 1.25rem, 1.625): explicación en tinta media, entre 44 y 56ch; textos legales a 1.0625rem, 1.7 y 70ch.
- **Body panel** (400, 0.875rem, 1.4): cuerpo base del panel del negocio y de los temas daisyUI.
- **Annotation** (Gochi Hand 400, de 1.125rem a 1.875rem, 1.25 a 1.375): día, tasa, rótulos de columna, «ejemplo», la cuenta del ejemplo escrita en la pizarra, notas como «Nombres y montos de ejemplo».
- **Label** (750, 1rem, ancho 92%, +0.005em): texto de botones.
- **Brand** (900, de 1.875rem a 2.125rem, ancho 72%, en minúsculas y en marcador azul): la palabra «apartar» en cabecera y pie.

### Named Rules
**The Annotation-Only Hand Rule.** La letra a mano es solo para anotar: el día, la tasa, los rótulos («En tu panel», «Hasta», «Tasa BCV de los últimos días»), «ejemplo» y la cuenta de ejemplo escrita en la pizarra. Nunca va en párrafos, nunca en botones, campos, navegación ni controles, y nunca en datos reales del panel.

**The Vinyl Caps Rule.** Todo titular de página o sección es vinilo condensado en mayúsculas (`font-vinyl`). El saludo del portal simulado en el inicio («Hola, María») no es un titular del sitio: imita el lado del cliente y por eso va en vinilo sin mayúsculas.

**The Tabular Figures Rule.** Toda la pizarra activa cifras tabulares (`font-variant-numeric: tabular-nums` en `.pizarra`). Los montos se alinean a la derecha de su renglón y se escriben al estilo venezolano (`$12,00`, `Bs. 42.874,56`).

## Layout

La pizarra ocupa el ancho de la ventana dentro de un marco: pared alrededor (6px en móvil, 12px en `sm`, 20px en `lg`), marco de aluminio con relleno de 6, 10 y 12px y bandeja de marcadores de 16 a 20px al pie. El marco llega a 1600px de ancho máximo; la cabecera y la primera pantalla a 1440px; las secciones a 1280px; los separadores a 1180px; los textos legales a 880px.

Márgenes laterales de 16px en móvil, 24px en `sm` y 48px en `lg`. Las secciones respiran 64px arriba y abajo, y 96px en `lg`; el cierre llega a 80px y 112px. Las secciones se separan con una raya de 2px en `rule-soft`, nunca con un cambio de fondo.

La primera pantalla del inicio es asimétrica (1.3fr de titular y CTA, 1fr de la cuenta de ejemplo); en móvil se apila: tasa, titular, CTA y la cuenta debajo, sin perder el CTA del primer pantallazo. Las comparaciones van en columnas separadas por un trazo vertical de 2px. Los pasos cuelgan de una línea de tiempo vertical con puntos azules. Precios divide sus planes con rayas de 2px (horizontales en móvil, verticales en `lg`).

Puntos de quiebre: `sm` 40rem, `md` 48rem (las tablas del panel se apilan por debajo), `lg` 64rem (cambio de móvil a escritorio y fin del modo táctil), `xl` 80rem. El modo táctil (`pointer: coarse` o menos de 64rem) sube los objetivos a 44px de alto.

**Dirección del panel del negocio (modo Operate, en construcción).** El panel hereda el mundo como herramienta: fondo acrílico o pizarra negra, Archivo como letra de trabajo, vinilo condensado solo para las cifras que mandan y letra a mano solo para el día, la tasa y los rótulos de columna. Su inicio es la pizarra del mostrador puesta a trabajar: tres columnas iguales en escritorio, «Por revisar» (cifra azul), «Por cobrar hoy» (cifra roja, atrasados primero) y «Este mes» (cifra verde), cada una con su cifra y su lista de personas con el botón que resuelve; en móvil se apilan en ese orden. Navegación lateral corta, con barra inferior en móvil. Estas reglas son dirección; no describen componentes que ya existan.

### Named Rules
**The Today-First Rule.** El tiempo ordena la página: el día y la tasa de hoy van arriba, a mano; lo de ayer queda debajo como rastro tachado y tenue; las fechas de las cuotas son el eje de la cuenta.

**The Ruled Lines, Not Cards Rule.** Las listas se escriben en renglones punteados (`pz-rule-dashed`: trazos de 5px cada 9px, 2px de alto, en `rule`), con el nombre a la izquierda y el monto a la derecha. No se encierra contenido en tarjetas con borde, fondo o sombra.

## Elevation & Depth

El sistema es plano. La profundidad la da el objeto físico, no la interfaz: el marco tiene un canto iluminado arriba (`inset 0 1px 0 frame-hi`) y una sombra corta sobre la pared, y la pizarra se hunde apenas dentro del marco. El contenido escrito sobre la pizarra no tiene elevación. Solo lo que flota sobre la pizarra (el menú móvil, el botón de volver arriba, los modales y menús del panel) se levanta con una sombra suave y difusa, acompañada de un borde de 2px en tinta. Las tarjetas del tema llevan `--card-shadow: none` y daisyUI corre con `--depth: 0`.

### Shadow Vocabulary
- **Marco sobre la pared** (`box-shadow: inset 0 1px 0 var(--pz-frame-hi), 0 6px 14px -6px rgb(0 0 0 / 0.3)`): solo el marco de aluminio.
- **Pizarra hundida** (`box-shadow: inset 0 1px 3px rgb(0 0 0 / 0.12)`): solo la superficie de escritura dentro del marco.
- **Labio del marcador** (`box-shadow: 0 1px 0 var(--pz-blue-deep)`): el canto inferior del botón azul.
- **Flotante** (`box-shadow: 0 14px 32px -10px rgb(0 0 0 / 0.35), inset 0 0 0 2px var(--pz-ink)`): menú móvil; el botón de volver arriba usa `0 10px 24px -10px`.
- **Emergente del panel** (`--pop-shadow`: `0 14px 32px -12px rgb(21 23 27 / 0.3)`; oscuro `0 16px 40px -10px rgb(0 0 0 / 0.6)`): modales y menús desplegables.

### Named Rules
**The No Decorative Shadow Rule.** Ninguna sombra adorna. Una sombra solo existe si describe el objeto físico (marco, pizarra, labio del marcador) o algo que de verdad flota sobre la pizarra. Un renglón, una cifra, un plan o una columna nunca llevan sombra.

## Shapes

Esquinas casi rectas, como las de un acrílico y su marco. El marco redondea 10px solo arriba; la pizarra, 5px dentro de él; la bandeja, 4px solo abajo. Botones, campos, menú y botón de volver arriba usan 6px; las cajas de daisyUI dentro de la pizarra, 8px. La píldora completa queda para lo que se elige o se cuenta: los chips de 2/3/4 cuotas, las pestañas de documentos legales, los segmentos de la barra de avance y el punto de la línea de tiempo.

Los bordes son trazos de marcador, no hilos: 2px en tinta para controles de línea (dibujados con `inset` para no mover la caja) y 2px en `rule` para separadores y líneas de tiempo. El círculo de marcador es un trazo SVG libre de 4px, abierto en su extremo, que encierra una cifra («Resta», la tasa de hoy) sin cerrar una caja.

## Components

### Buttons
Marcadores firmes y cortos: un solo azul por zona, todo lo demás de línea.
- **Shape:** esquinas suaves (6px), alto mínimo de 48px y 56px en los CTA de la primera pantalla y del cierre; ocupan todo el ancho en móvil.
- **Primary:** marcador azul con texto blanco, Archivo 750 al 92% de ancho, 24px de relleno lateral («Empezar gratis», «Ir a mi negocio»).
- **Hover / Focus:** el azul pasa a azul hondo en 160ms con `--ease-marker`; al presionar baja 1px. El foco es un anillo de 3px en azul con 3px de separación.
- **Line:** sin fondo, texto en tinta y borde interior de 2px en tinta («Ver planes y precios», «Entrar»); en hover se llena con `ghost-ink`.
- **Paper:** existe en la hoja (`pz-btn-paper`, pizarra con texto azul) pero ningún componente lo usa todavía.

### Chips
- **Style:** píldoras de 40px (44px en táctil), Archivo 700 a 0.9375rem, borde interior de 2px en tinta.
- **State:** el elegido se rellena de tinta con texto de pizarra y expone `aria-pressed`; los demás se llenan de `ghost-ink` en hover. Se usan para elegir 2/3/4 cuotas y para cambiar entre Términos y Privacidad.

### Cards / Containers
- **Corner Style:** no hay tarjetas en la pizarra; los grupos se separan por renglones y rayas.
- **Background:** la pizarra misma (`pz-board-surface`).
- **Shadow Strategy:** ninguna (ver Elevation & Depth).
- **Border:** renglones punteados entre filas, rayas de 2px entre secciones o planes.
- **Internal Padding:** filas de 6px arriba y abajo en la cuenta, 12px en listas de personas, 24px en los pasos.

### Inputs / Fields
- **Style:** campos de daisyUI dentro del alcance `.pizarra`: fondo `board-hi`, borde de 1px en `line-strong`, esquinas de 6px, 44px de alto en táctil y 1rem de letra en móvil.
- **Focus:** el borde y un contorno de 1px pasan a marcador azul, sin halo ni sombra.
- **Error / Disabled:** el error pasa el borde a rojo y el mensaje se dice en lenguaje llano; deshabilitado usa `surface-2` y tinta tenue.

### Navigation
- **Style:** la cabecera vive en el borde superior de la pizarra (64px, 84px en `lg`): la marca «apartar» a la izquierda, enlaces en Archivo 600 a 0.9375rem en tinta, «Entrar» de línea y «Empezar gratis» en azul.
- **States:** hover subraya con 0.3em de separación; la página actual se subraya en azul (`aria-current="page"`).
- **Mobile:** un botón cuadrado de 44px con borde de tinta abre un menú flotante sobre la pizarra, con la sombra flotante. Hay un enlace «Saltar al contenido» que aparece al enfocarlo.
- **Footer:** fuera del marco, sobre la pared: marca, una línea de qué es Apartar y columnas de enlaces con encabezado corto en mayúsculas.

### Cuenta de ejemplo (componente firma)
La cuenta de un apartado escrita en la pizarra: producto a mano con la anotación «ejemplo» inclinada, el precio en vinilo con su equivalente en Bs. de hoy en azul a mano, renglones de inicial y cuotas con fecha (las pagadas tachadas con trazo de 3px y ✓ verde), «Resta» en rojo con el monto encerrado en un círculo de marcador, y los chips de 2/3/4 cuotas debajo. Se ve completa al cargar. Al elegir otro número de cuotas, la cuenta anterior se borra (`pz-erase`, 900ms, se desvanece con 2px de desenfoque), la nueva se escribe de izquierda a derecha renglón por renglón (`pz-write`, 520ms, 70ms entre renglones) y el círculo de «Resta» se vuelve a trazar (`pz-circle`, 700ms). Con movimiento reducido el cambio es inmediato.

### Línea de la tasa (componente firma)
Arriba a la izquierda, a mano: «Hoy, ‹día de la semana› ‹fecha› · Tasa BCV Bs. ‹tasa›», con la cifra en azul y la fecha de publicación cuando no es de hoy. Debajo, la tasa anterior como rastro: más pequeña, tenue, girada −1°, tachada y al 70%. En el historial de tasas, la de hoy va encerrada en un círculo azul y las anteriores en tinta tenue, con notas a mano para los días sin publicación.

### Barra de avance
Una fila de segmentos en píldora de 14px, uno por cuota: los pagados en marcador verde, los pendientes vacíos con borde de 2px en `rule`. Es la única barra de avance; el panel la usará igual en todas sus pantallas.

## Do's and Don'ts

### Do:
- **Do** usar el marcador azul para la acción y para lo que dice Apartar, el rojo solo para lo que resta y lo atrasado, el verde solo para lo pagado, el ámbar solo para lo que está en revisión y la tinta para el texto.
- **Do** escribir todo titular de página o sección en vinilo condensado en mayúsculas (`font-vinyl`: Archivo al 68%, 850, interlineado 0.9).
- **Do** usar la letra a mano solo para anotaciones: el día, la tasa, los rótulos y «ejemplo».
- **Do** alinear montos con cifras tabulares a la derecha de renglones punteados, con formato venezolano (`$1.234,56`, `Bs. 1.234,56`).
- **Do** mostrar el día y la tasa de hoy arriba, con lo de ayer como rastro tachado y tenue debajo.
- **Do** dejar el contenido visible por defecto; la animación de marcador (`pz-write`, `pz-erase`, `pz-circle`) solo corre cuando la cuenta se reescribe, y se apaga con `prefers-reduced-motion`.
- **Do** diseñar cada pantalla en los dos materiales: acrílico claro y pizarra negra.
- **Do** rotular cada parte de la cuenta (inicial, cuota, resta, tasa) y marcar como ejemplo todo dato que no sea real.

### Don't:
- **Don't** pintar con rojo, verde o ámbar algo que no sea lo que resta o lo atrasado, lo pagado o lo que está en revisión.
- **Don't** usar la letra a mano en párrafos, botones, campos, navegación ni datos reales del panel.
- **Don't** encerrar contenido en tarjetas con borde, fondo o sombra; usa renglones punteados y rayas de 2px.
- **Don't** agregar sombras decorativas; la sombra solo existe para el marco, la pizarra hundida, el labio del marcador o lo que flota.
- **Don't** ocultar contenido hasta que una animación de entrada lo revele.
- **Don't** llevar la pizarra a la tienda, el catálogo, la ficha de producto, el flujo de apartar o el portal del cliente: allí el protagonista es el negocio y Apartar solo firma el plan de cuotas.
