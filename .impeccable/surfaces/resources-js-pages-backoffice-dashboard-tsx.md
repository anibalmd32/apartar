---
version: 1
slug: "resources-js-pages-backoffice-dashboard-tsx"
primary_target: "resources/js/pages/backoffice/Dashboard.tsx"
related_targets: ["resources/js/layouts/backoffice-layout.tsx"]
---

Alcance: panel del negocio (`/backoffice`, en producción `negocio.apartar.app`): el marco (navegación lateral, cabecera, barra inferior móvil), el inicio y, por herencia, todas sus pantallas. Modo: Operate.

Público: el vendedor y su equipo, muchas veces desde el teléfono y entre conversaciones de WhatsApp. Tareas frecuentes: aprobar comprobantes, ver y recordarle a quien debe, cargar productos, compartir catálogos. Estados clave: comprobante por revisar, cuota que vence hoy, cuota atrasada (días), pagado completo y por entregar. Restricciones: teléfonos de gama baja, poca experiencia digital, claro y oscuro, dinero en $ y Bs. al estilo venezolano.

Evidencia de la crítica (2026-11-04): el inicio hoy prioriza el consumo del plan sobre a quién cobrar; vocabulario de atraso contradictorio entre pantallas; no se puede recordar por WhatsApp desde la cobranza; 18 entradas de navegación; barras de avance y estados con colores distintos según la pantalla; la mezcla de monedas al registrar un pago manual.

## Direction contract

THESIS: El panel es la pizarra del mostrador puesta a trabajar: tres columnas de lo que toca hoy (por revisar, por cobrar hoy, lo que entró este mes), cada una con su cifra y su lista de personas con el botón que resuelve. Rechaza la fila de tarjetas de cifras con icono, el consumo del plan en el centro y la barra lateral de 18 entradas.

OWN-WORLD: El mismo mundo de la landing en modo herramienta: fondo acrílico (claro) o pizarra negra (oscuro), Archivo como letra de trabajo, cifras de vinilo condensado solo para los números que mandan, letra a mano solo para el día, la tasa y los rótulos de columna. Roles de marcador fijos en todo el panel: azul = acción y lo de Apartar, rojo = atrasado o lo que resta, verde = pagado, ámbar = en revisión, tinta = texto. Renglones punteados en vez de tarjetas; una sola barra de avance (verde lo pagado) en todas partes.

STORY: Al abrir sabe en un vistazo cuántos comprobantes esperan, cuánto le deben hoy y a quién, y cuánto entró. Resuelve desde la misma fila: Revisar, Recordar por WhatsApp, Marcar entregado.

FIRST VIEWPORT: Arriba, a mano, el día y la tasa BCV de hoy. Debajo, tres columnas iguales en escritorio: «Por revisar» (cifra azul y lista con «Revisar»), «Por cobrar hoy» (cifra roja, clientes con atraso primero, botón «WhatsApp»), «Este mes» (cifra verde de lo que entró, lo que falta y la actividad reciente). En móvil las columnas se apilan en ese orden, con la primera acción a la vista. Navegación: lateral corta (Hoy, Comprobantes, Cobranza, Clientes, Chat, Productos, Catálogos y «Más»); en móvil, barra inferior.

FORM: «Pizarra del mostrador», candidata 5 de mi lista ordenada de 7 estructuras, repartida por el sorteo de superficie y elegida por el dueño. Mundo: seed:fdaee56b148174fd. Riesgo nombrado: no caer en la fila de tarjetas de cifras; la cifra vive dentro de su columna con su lista, sin icono ni caja. Interacción firma: al resolver una fila (aprobar, recordar, entregar) se tacha con trazo de marcador y baja la cifra de su columna; con movimiento reducido, cambio inmediato.

FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance

Sin decidir: si el consumo del plan se queda solo en Suscripción o aparece cuando se acerca a un límite (propuesta: solo cerca del límite).
