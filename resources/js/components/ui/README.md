# `components/ui` — átomos daisyUI

Directorio reservado por [05 §3.1](../../../docs/specs/05-design-tecnico.md) y
`REQ-QAX-001` RB-5: aquí viven los **envoltorios tipados de daisyUI 5**, y solo
ellos. Las páginas nunca aplican clases de daisyUI en crudo; las piden a un
átomo de aquí, que es lo que permite cambiar el sistema visual en un sitio.

Cada átomo nace con la tarea que lo necesita. Los tres primeros llegaron con
`T-010`, la primera pantalla propia del proyecto:

| Átomo                | Qué resuelve                                                                                  | Tarea   |
| -------------------- | --------------------------------------------------------------------------------------------- | ------- |
| `button.tsx`         | `btn` con variante, tamaño y estado de carga que **deshabilita**                              | `T-010` |
| `text-field.tsx`     | etiqueta asociada, pista, error anunciado a lectores y prefijo dentro del recuadro            | `T-010` |
| `alert.tsx`          | avisos con `role="alert"` siempre                                                             | `T-010` |
| `select-field.tsx`   | lo mismo que `text-field`, con las opciones como **datos tipados** y no como JSX              | `T-013` |
| `badge.tsx`          | estado corto en una fila; el color nunca es lo único que distingue                            | `T-013` |
| `checkbox-field.tsx` | interruptor cuya etiqueta **envuelve** al control, para que el área pulsable sea toda la fila | `T-014` |
| `file-field.tsx`     | selector de archivo con vista previa de lo que ya hay guardado                                | `T-014` |

`select-field.tsx` admite `hideLabel` desde `T-013b`: oculta la etiqueta a la
vista **sin quitarla**, para las columnas de tabla donde la cabecera ya la dice
y repetirla en cada fila sería ruido. Un `select` sin nombre accesible se
anuncia como «cuadro combinado» y nada más, así que la etiqueta sigue ahí en
`sr-only`.

Lo que **no** es un átomo: las clases de composición de daisyUI —`card`,
`table`, `menu`— las aplican las páginas y los marcos directamente, como hace
`marketing/Register.tsx` desde `T-010`. Aquí viven los **controles**, que son
los que acumulan reglas de accesibilidad que nadie quiere repetir. El listado
genérico con filtros y orden es otra cosa y tiene su sitio reservado en
`features/data-table/` ([05 §8]), con la tarea que lo necesite.

Los primitivos de shadcn/Radix que trajo el starter kit se movieron a
[`../vendor/`](../vendor/README.md).
