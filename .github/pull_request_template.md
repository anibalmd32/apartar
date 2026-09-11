# Requisitos que cierra

<!--
REQ-QAX-006 RB-7: todo PR declara por ID qué requisitos cierra. Es el
sustituto ligero de la trazabilidad automatizada que se perdió al descartar
`indexion`, y sin esta lista la revisión no puede empezar (SP-2).
Ejemplo: `REQ-PAY-005`, `REQ-PAY-007`.
-->

-

**Tarea del plan:** <!-- T-0NN -->
**Fase:** <!-- A (tipos y estructuras) · B (lógica) · AB -->

## Qué cambia

<!-- Dos o tres frases. El detalle está en el diff. -->

## Las cuatro líneas del gate

Para cada requisito declarado arriba, comprobado contra su sección *Gate de
validación* (`REQ-QAX-006` RB-1):

- [ ] **Superficie esperada** — los archivos que el requisito declara existen y contienen lo que describe.
- [ ] **Tipos esperados** — las clases, enums, VOs, excepciones y tipos TS existen con su nombre exacto.
- [ ] **Anti-`SHALLOW`** — la función que el requisito nombra tiene lógica real. Tipos creados con métodos vacíos **no cuentan como entregado** (RB-3). En Fase A esto queda tolerado y se marca como tal.
- [ ] **Tests** — los casos listados en el gate pasan.

## Documentación (`REQ-QAX-007`)

- [ ] **PHPDoc** en toda clase, interfaz, enum, método público y propiedad promovida nueva: propósito, `@param`, `@return`, `@throws` y genéricos de colección (`list<Installment>`, `Collection<int, Layaway>`).
- [ ] Cada clase de `app/Domain` explica **su responsabilidad y su invariante principal** (RB-7).
- [ ] **TSDoc** en todo hook, tipo exportado y componente público: qué hace, qué recibe, qué devuelve y sus efectos.
- [ ] El vocabulario de la documentación es el **del glosario de la spec**, no sinónimos improvisados (RB-4).
- [ ] Sin comentarios obvios en el cuerpo; el comentario explica **por qué**, no qué (RB-2).

## Aislamiento multi-tenant

- [ ] Ninguna consulta nueva sobre una tabla del tenant corre sin filtro por `organization_id`.
- [ ] Todo índice único de negocio nuevo incluye `organization_id`, o entra en la lista blanca declarada.
- [ ] Si la tarea añade una consulta del tenant, su caso está en `tests/Feature/Tenancy` (`REQ-QAX-008` RB-2).

## Comprobaciones

- [ ] `composer check` en verde (Pint, PHPStan nivel 6, Pest).
- [ ] `npm run check` en verde (ESLint, `tsc --noEmit`, Vitest, build).
- [ ] Los tres jobs de CI pasan, sin ningún paso saltado.

## Desviaciones respecto a la spec

<!--
REQ-QAX-006 SP-3 y SP-5: si la implementación se aparta de lo especificado,
la spec se corrige **en este mismo PR**. Déjalo escrito aquí o pon "ninguna".
-->

Ninguna.
