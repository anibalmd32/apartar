# `components/ui` — átomos daisyUI

Directorio reservado por [05 §3.1](../../../docs/specs/05-design-tecnico.md) y
`REQ-QAX-001` RB-5: aquí viven los **envoltorios tipados de daisyUI 5**, y solo
ellos. Las páginas nunca aplican clases de daisyUI en crudo; las piden a un
átomo de aquí, que es lo que permite cambiar el sistema visual en un sitio.

Está vacío a propósito. Cada átomo nace con la tarea que lo necesita.

Los primitivos de shadcn/Radix que trajo el starter kit se movieron a
[`../vendor/`](../vendor/README.md).
