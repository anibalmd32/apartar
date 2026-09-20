# PROMPT 30 — `T-030` · Estados de publicación

> Hito **H2 · Catálogo** · Tarea `T-030` · Fase `B` · 2 pts
> **Cierra `REQ-CAT-007`** en lo que no depende de la página pública (§4).
> Depende de `T-027` (`cc708ec`, `ChangeProductStatusAction` a medias) y de `T-029` (`fa5501f`, `Product::hasImages()`).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-030: estados de publicacion`.

## 0. Qué vas a hacer

Los tres estados del producto y las transiciones entre ellos: publicar —con todo lo que publicar exige—, archivar y desarchivar. Es una tarea corta con una responsabilidad grande: **aquí se decide qué ve un desconocido y qué no**, y eso hasta ahora no lo decidía nadie.

Dos piezas te esperan escritas a propósito: `ChangeProductStatusAction`, que `T-027` dejó con su punto de extensión, y `Product::hasImages()`, que `T-029` escribió para ti.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → **`REQ-CAT-007`** | tus nueve reglas y siete `SP-`; RB-6 es la que cambia cómo se piensa la visibilidad |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → `REQ-BOF-020` **RB-5** y SP-3 | la lista exacta de lo que exige publicar, y el mensaje que espera quien la incumple |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-027`…`T-035`, `T-066`, `T-067` | lo que heredas y lo que no es tuyo (§4) |
| `app/Actions/Product/ChangeProductStatusAction.php` | **tu punto de partida**: `archive()` ya existe y `transitionTo()` audita por todas |
| `app/Models/Product.php`, `app/Domain/Catalog/ProductStatus.php` | `hasImages()`, los casts y los dos métodos derivados del enum |
| `app/Models/Catalog.php` → `visibility()` | el precedente de cómo se deriva un estado en este proyecto, y su orden de evaluación |

## 2. Alcance

**Sí:**

1. **Completar `ChangeProductStatusAction`**: la matriz de transiciones, `publish()` y `restore()`, sobre el `transitionTo()` que ya existe.
2. **La completitud de RB-5**, entera y en un solo sitio, con **`IncompleteProductException`** y **la lista de lo que falta** (SP-3).
3. **`Product::scopePublished()`**, que evalúa `published_at <= now()` **en la consulta** (RB-5 de `REQ-CAT-007`).
4. **`ProductNotAvailableException`** (SP-2).
5. Las rutas y la parte de pantalla que las transiciones necesiten, con sus dos declaraciones.
6. Sus tests.

**No:**

- **No** escribas la página pública ni el catálogo público: el `404` de SP-1 se verifica en **`T-033`** y **`T-035`** (§4).
- **No** toques el cupo del plan (RB-8, SP-4 en su parte de cuota): **`T-066`** mide la métrica y **`T-067`** inserta las llamadas.
- **No** reescribas ni muevas el archivado que `T-027` dejó, ni su evento de auditoría.
- **No** metas un job de publicación programada: RB-5 dice **explícitamente** que se evalúa en la query.

## 3. La completitud vive aquí, y solo aquí

`REQ-BOF-020` RB-5 exige cuatro cosas para publicar: **al menos una imagen, categoría asignada, descripción no vacía y condiciones financieras válidas**. Está consolidada en esta tarea a propósito —lo dice el plan bajo `T-029` y bajo esta— porque con dos listas de requisitos para publicar repartidas entre tareas, la que se queda corta **no falla**: publica de menos o de más, y nadie se entera hasta que un cliente ve una ficha sin foto.

Tres detalles que la hacen útil en vez de correcta:

- **La excepción lleva la lista de lo que falta** (SP-3), no un booleano ni un mensaje genérico. Quien pulsa «Publicar» necesita saber que le faltan la foto y la categoría, no que «el producto está incompleto».
- **Las condiciones financieras ya tienen su regla escrita** (`CoherentFinancingTerms`, `T-027`). No la reimplementes: un producto guardado en `draft` pasó por ella, pero un `draft` **puede estar incompleto por definición**, así que la comprobación al publicar es sobre el estado actual de la fila.
- **`hasImages()` es de `T-029` y contesta la misma pregunta que SP-4 de `REQ-BOF-022`** —no dejar sin fotos un producto ya publicado—. Dos momentos distintos, un solo dato: no escribas una segunda forma de contar imágenes.

## 4. Lo que no puedes comprobar todavía, y quién lo comprueba

- **SP-1, el `404` de un `draft` o un `archived` por URL directa.** No hay página pública hasta `T-033` (catálogo) y `T-035` (ficha). Lo tuyo es el **mecanismo**: `scopePublished()` y que la consulta pública no tenga forma de traerlos. El `404` como respuesta HTTP se verifica en aquellas tareas, y así queda escrito en el plan.
- **SP-4 en su mitad de cupo**: restaurar un archivado «vuelve a consumir cupo del plan (si no hay, `422`)». Lo que **sí** es tuyo, y es lo importante de SP-4: **restaurar devuelve a `draft`, nunca directamente a `published`**. Un producto que vuelve publicado se cuela en catálogos con datos que nadie ha revisado desde que se archivó.
- **RB-8**, que los archivados y los borrados lógicamente no cuenten para la métrica: es la definición que `T-066` implementará en `measure()`. Tú no cuentas nada.

## 5. La visibilidad es una intersección, y el backoffice tiene que decirlo

RB-6: un producto puede estar `published` y **no verlo nadie**, si no está en ningún catálogo accesible. No es un error (SP-6) y el backoffice lo indica: «publicado, en 0 catálogos». La columna de cuántos catálogos ya existe desde `T-027`.

Es la misma forma de pensar que `Catalog::visibility()`: un estado que se **deriva** y no se guarda. Y aquí tiene una consecuencia que conviene no perder — **`ProductStatus::isVisibleInCatalog()` responde por el estado del producto y nada más**. No sabe en qué catálogo está ni si ese catálogo es accesible; quien cruza las dos cosas es la consulta pública de `T-033`.

## 6. Cinco cosas que se tuercen solas

1. **`published_at` futuro es publicación programada** (RB-5, SP-5), evaluada **en la consulta**: `scopePublished()` filtra por `published_at <= now()` y el backoffice lo marca «Programado». Un job que «publique» a la hora es un segundo mecanismo que puede no correr, y entonces el producto se queda invisible sin que nada falle. Ojo con la asimetría ya declarada: en un **catálogo**, una fecha futura cuenta como publicado (`REQ-CAT-022`); en un **producto**, no. Está escrita en [12](../specs/12-catalogos-curados.md) junto al enum.
2. **Archivar no toca los catálogos** (RB-7): la relación se conserva para que desarchivar devuelva el producto sin recomponer nada. La relación de `T-025` no filtra por estado justamente por eso; si al archivar «limpias» el pivote, RB-7 queda desactivado y nadie lo nota hasta que alguien desarchiva.
3. **Archivar no afecta a los apartados vivos** (RB-3). Hoy no hay apartados: lo que te toca es **no** escribir ningún recorrido que los busque.
4. **`stock = 0` no cambia el estado** (RB-4), y eso ya está resuelto y probado desde `T-027`. Si te ves añadiendo una transición automática por stock, para: agotado es una etiqueta en la pantalla, no un estado.
5. **La auditoría ya funciona**: `transitionTo()` emite `ProductStatusChanged` una sola vez, para todas las transiciones. Añadir un evento por transición nueva sería crecer la lista cerrada de `REQ-BOF-005` RB-1 sin tocar la spec.

## 7. Hecho cuando

- [ ] **Matriz de transiciones** completa, con las válidas y las inválidas, sobre el `transitionTo()` existente.
- [ ] **Publicar incompleto → `IncompleteProductException` con la lista de lo que falta** (RB-5, SP-3), con test por cada una de las cuatro condiciones.
- [ ] **Restaurar un archivado deja `draft`**, nunca `published` (SP-4).
- [ ] `scopePublished()` filtra por estado **y** por `published_at <= now()`, sin job (RB-5, SP-5).
- [ ] **La relación con el catálogo se conserva al archivar** (RB-7), con test.
- [ ] **Publicado en 0 catálogos no es un error** y el backoffice lo indica (RB-6, SP-6).
- [ ] `ProductNotAvailableException` existe y se lanza donde SP-2 la pide.
- [ ] Ninguna llamada a `LimitEnforcer`, ningún evento de auditoría nuevo (§4, §6.5).
- [ ] Las rutas nuevas declaran **superficie** y **supervivencia a suspensión**; `tenancy:audit` y la suite de tenancy en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Cómo quedó la matriz: qué transiciones son válidas, cuáles no, y qué pasa al repetir una que ya está aplicada.
2. Cómo se compone la lista de lo que falta al publicar, y qué ve exactamente quien pulsa «Publicar» con un producto sin foto ni categoría.
3. Dónde y cómo se fija `published_at` al publicar, y qué pasa si alguien manda una fecha futura.
4. Qué parte de `REQ-CAT-007` queda sin verificar hasta `T-033`/`T-035`, y por qué no se puede hoy.
5. Si al completar `ChangeProductStatusAction` encontraste algo que `T-027` dejara mal planteado, qué y dónde. **No lo tapes reescribiéndolo en silencio.**
6. Nada más. No propongas siguientes pasos.
