# PROMPT 29 — `T-029` · Imágenes de producto

> Hito **H2 · Catálogo** · Tarea `T-029` · Fase `B` · 3 pts
> **Cierra `REQ-BOF-022`** y la parte de **`REQ-CAT-005`** que no necesita la galería pública (§4).
> Depende de `T-027` (`cc708ec`, el producto y su formulario) y de `T-028` (`a1785c6`, el reparto con `T-035`, que aquí se repite).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 88 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-029: imagenes de producto`.

## 0. Qué vas a hacer

Las fotos del producto: subirlas, ordenarlas, elegir la principal y generar las tres conversiones en cola. Es la primera vez que este proyecto escribe **archivos de un negocio en disco**, así que trae dos cosas que no ha habido hasta ahora: el aislamiento físico —la ruta lleva el `organization_id`— y un trabajo en cola que tiene que correr **en el contexto del tenant**.

## 1. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → **`REQ-BOF-022`** | tus nueve reglas y nueve `SP-` |
| [docs/specs/11-catalogo-y-productos.md](../specs/11-catalogo-y-productos.md) → **`REQ-CAT-005`** y el contrato `ImageSet` | las conversiones, el `alt` por defecto y qué se sirve mientras no existen |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → `T-029`, `T-030`, `T-035`, `T-067` | **tres piezas tienen dueño en otra tarea** (§4) |
| [docs/specs/90-infraestructura-y-entorno.md](../specs/90-infraestructura-y-entorno.md) | dónde vive el disco y cómo está configurado el almacenamiento |
| `app/Jobs/Concerns/RunsInTenantContext.php`, `app/Jobs/FetchExchangeRate.php` | cómo se escribe un job en este proyecto, y por qué el tenant no viaja solo |
| `tests/Feature/Tenancy/JobTenantContextTest.php` | lo que tu job tiene que cumplir para que la suite pase |

## 2. Alcance

**Sí:**

1. **Medialibrary sobre `Product`**: colección `images`, las tres conversiones (`thumb` 400, `card` 800, `zoom` 1600, WebP calidad 82) y la ruta con `organization_id`.
2. **`TranscodeProductImage`**, en cola, corriendo en el contexto del tenant.
3. **`ProductImageController`** con sus tres rutas, **`RealMimeType`** y **`SyncImageOrderAction`**.
4. **`ImageUploader`** con vista previa local y progreso por archivo, y la galería del backoffice.
5. **`ImageSet`** y **el `alt` por defecto** de `REQ-CAT-005` RB-4.
6. **Que el producto sepa decir si tiene imágenes**, que es la pieza que `T-030` necesita (§4).
7. Sus tests, incluido el de aislamiento físico.

**No:**

- **No** escribas la comprobación de completitud para publicar: es **`T-030`**, entera. Lo tuyo es SP-4, que es otra cosa (§4).
- **No** llames a `LimitEnforcer` ni implementes la cuota de `storage_mb`: **`T-066`** enseña a medirla y **`T-067`** inserta las llamadas (§4).
- **No** escribas la galería pública ni `ProductDetailResource`: son **`T-033`** y **`T-035`**.
- **No** añadas recorte, rotación, biblioteca de medios reutilizable, vídeo ni CDN: están fuera de alcance explícito.

## 3. El aislamiento aquí también es físico

RB-9 y `REQ-CAT-005` RB-7: **la ruta de cada archivo incluye el `organization_id`**, y no es decoración. Hasta ahora el aislamiento de este proyecto ha vivido en el *global scope*; un archivo en disco no tiene *global scope*. Si las imágenes de todos los negocios caen en el mismo directorio bajo un identificador correlativo, el aislamiento pasa a depender exclusivamente de que ninguna URL se adivine — y el día que haya que auditar qué archivos son de quién, la respuesta está en una tabla y no en el disco.

Dos consecuencias que conviene tener presentes:

- El generador de rutas es **de medialibrary**, no del controlador: si lo resuelve quien sube, la próxima colección que alguien añada volverá a decidirlo por su cuenta.
- **El borrado sí borra** (RB-7). Es lo contrario de los comprobantes de `REQ-PAY-002` RB-8, que son evidencia y nunca se borran. La diferencia es de quién es el archivo y para qué existe: una foto de catálogo es material del negocio, un comprobante es la prueba de un pago ajeno.

## 4. Tres piezas de tus requisitos que **no** son tuyas

1. **La cuota de `storage_mb`** (RB-8, SP-7, y `REQ-CAT-005` RB-6). `LimitEnforcer` existe con su firma definitiva desde `T-011`, pero **`T-066`** añade la métrica a `measure()` y **`T-067`** inserta las doce llamadas. Hoy `storage_mb` devuelve «sin límite» a propósito. El test del gate que compara los dos lados —la subida del negocio se rechaza y el comprobante del cliente no— necesita además `receipts`, que es H4: va con `T-067`.
2. **«Publicado sin imágenes rechazado»** es de **`T-030`**, donde la completitud de `REQ-BOF-020` RB-5 se comprueba entera y en un solo sitio. Tú aportas la pieza: que el producto sepa contestar si tiene imágenes.
   **Ojo con no confundirlo con SP-4, que sí es tuyo**: borrar la **única** imagen de un producto **ya publicado** es `422`; en `draft` se permite. Son dos momentos distintos —publicar y borrar— y el segundo es el que tienes delante.
3. **La galería pública** (`REQ-CAT-005` RB-8, RB-3 del lado que renderiza): `T-033` y `T-035`. Lo tuyo del contrato compartido es `ImageSet` y el `alt` por defecto, escritos donde esas tareas los puedan llamar — el mismo reparto que `T-028` hizo con la agrupación de la ficha técnica.

## 5. El job corre en el contexto del tenant, y eso no pasa solo

`REQ-CAT-005` RB-1: las conversiones se generan **en cola**, nunca en el request de subida. Y un job de este proyecto no hereda la organización de quien lo despachó: `RunsInTenantContext` existe porque el *global scope* se alimenta de `TenantContext`, que es de la petición. `JobTenantContextTest` recorre los jobs y lo exige.

Lo que se tuerce si se olvida no es un error: es un job que corre **sin** organización fijada y que, según lo que consulte, o lanza o trabaja sobre la fila equivocada.

Y su compañera, SP-6 / SP-1: **un job de conversión que falla no deja el producto inservible**. La imagen queda utilizable con el original y marcada para revisión, y mientras la conversión no exista se sirve el original con `loading="lazy"` (RB-3) — nunca un hueco roto. Un producto publicable cuyas fotos dependan de que la cola vaya bien es un catálogo que se apaga cuando falla un *worker*.

## 6. Seis cosas que se tuercen solas

1. **El MIME se valida de verdad** (RB-1, SP-3): por el contenido del archivo, no por la extensión ni por el `Content-Type` que manda el navegador, que es texto que escribe el cliente. `RealMimeType` es la regla y es la que hay que escribir.
2. **La principal es `position = 1`** (RB-2) y **borrarla promueve a la siguiente** (RB-4), sin dejar un hueco: es la misma renumeración contigua que `CategoryOrder` hace desde `T-026` y que la ficha técnica hace desde `T-028`. Tres sitios con la misma regla; si el tuyo la escribe distinto, alguien acabará arreglando el que no tocaba.
3. **`SyncImageOrderAction` comprueba pertenencia al tenant antes de reordenar.** Un `media` id de otro negocio en la lista no puede acabar renumerado dentro de este producto: la tabla `media` está declarada fuera del filtro por organización —se ata a su modelo por relación polimórfica—, así que aquí la guarda la pones tú, como `T-027` la puso para las etiquetas.
4. **Reordenamiento concurrente: gana el último** (SP-8). Ya es la decisión vigente en las otras dos listas ordenables.
5. **Subida interrumpida: nada se persiste para ese archivo** (SP-5), y los ya subidos permanecen. Es decir, cada archivo es su propia unidad de trabajo; no envuelvas los diez en una transacción que los tire todos porque el séptimo se cortó.
6. **El `alt` por defecto es `"{{ product.name }} — imagen {{ n }}"`** (RB-4) y es **editable** (RB-6). Un `alt` vacío o repetido en las diez imágenes es una ficha que no se puede leer con lector de pantalla, y el valor por defecto existe para que eso no dependa de que alguien lo escriba.

## 7. Hecho cuando

- [ ] Las **tres rutas** existen con **superficie** y **supervivencia a suspensión** declaradas; producto ajeno → `404` (SP-9).
- [ ] **Límite de 10 imágenes** (SP-1), **8 MB por archivo** (SP-2) y **MIME real** (SP-3), los tres con test.
- [ ] Las **tres conversiones** se generan **en cola**, y el job corre **en el contexto del tenant** (§5).
- [ ] Mientras una conversión no exista **se sirve el original**; un job fallido deja la imagen utilizable y marcada (SP-1, SP-6).
- [ ] **Borrar la principal promueve a la siguiente** sin dejar hueco (RB-4).
- [ ] **Borrar la única imagen de un producto publicado → `422`; en `draft` se permite** (SP-4).
- [ ] **La ruta física incluye el `organization_id`**, resuelta en el generador de rutas y con test (§3).
- [ ] **Borrar una imagen borra el archivo del disco** (RB-7).
- [ ] `SyncImageOrderAction` **rechaza un `media` ajeno**, con test (§6.3).
- [ ] El producto **sabe decir si tiene imágenes**, listo para `T-030`.
- [ ] `ImageSet` y el `alt` por defecto quedan donde `T-033` y `T-035` los llamen.
- [ ] `tenancy:audit`, la suite de tenancy y `JobTenantContextTest` en verde.
- [ ] Las recetas de calidad en verde, sin baseline ni `@phpstan-ignore` nuevos.
- [ ] **Commit hecho.**

## 8. Reporta al terminar

1. Dónde se decide la ruta física y qué impide que la próxima colección de medios la resuelva por su cuenta.
2. Qué pasa exactamente con un producto publicado cuyas tres conversiones aún no existen, y qué ve quien abre esa pantalla.
3. Cómo distingue tu código el caso de SP-4 (borrar la última imagen de un publicado) del que `T-030` va a comprobar al publicar, y por qué no son la misma comprobación escrita dos veces.
4. Qué hiciste con la cuota de almacenamiento y qué queda exactamente para `T-067`.
5. Si `REQ-BOF-022` o `REQ-CAT-005` te pidieron algo que hoy no se puede hacer, cuál y de qué tarea es. **No lo des por hecho ni lo tapes.**
6. Nada más. No propongas siguientes pasos.
