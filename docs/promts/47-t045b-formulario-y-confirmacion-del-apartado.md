# PROMPT 47 — `T-045b` · Creación del apartado: el formulario y la confirmación

> Hito **H3 · Apartado** · Tarea `T-045b` · Fase `B` · 2 pts
> **Cierra la superficie de `REQ-LAY-001`** y **`REQ-IDN-005`**.
> Depende de `T-045` (`4ab2d0e`, `CreateLayawayAction` ya escrita y probada), `T-035` (la ficha y su CTA sin destino) y `T-037` (`b3ea2b9`, las páginas legales sin redactar).

## Arranque en frío

Si es tu primera sesión en este repositorio, esto es lo que necesitas saber antes de leer nada más:

**Apartar** es un SaaS multi-tenant donde un negocio publica catálogos con sistema de **apartado** (layaway) y gestiona los pagos de sus propios clientes. La especificación está **cerrada y es la autoridad**: 118 requisitos en 19 módulos, un diseño técnico y un plan de 90 tareas, todo en `docs/specs/`. Empieza por [docs/specs/00-INDEX.md](../specs/00-INDEX.md), que es el mapa.

El trabajo avanza **una tarea por sesión**, y cada tarea tiene su prompt en `docs/promts/`. Este es el tuyo: haz **solo** lo que dice, y nada de las tareas siguientes.

Para ver dónde está el proyecto: `git log --oneline` (cada commit es una tarea cerrada) y `docs/specs/06-plan-de-tareas.md`, cuya sección "Cómo leer este plan" recoge las convenciones vigentes, y cuyas notas bajo cada tarea recogen las decisiones ya tomadas. Las recetas de calidad son scripts de `composer` y de `npm`; `make` es un alias opcional y puede no estar instalado. El stack corre en Docker (`docker compose up -d`, **levántalo antes de nada**), la aplicación en `http://localhost:8080`, y los tests necesitan el MySQL del contenedor.

**Haz commit al terminar**, con el mensaje `T-045b: formulario y confirmacion del apartado`.

## 1. Cómo correr las pruebas

1. **Mientras trabajas**, solo lo tuyo.
2. **Los baratos primero**: `composer lint`, `composer analyse`, `composer test:quality`, y `npx prettier --check` sobre **los ficheros que tocaste** —son segundos, y esta tarea es casi toda frontend—.
3. **`composer check`** —ya incluye el gate de cobertura: **no lo lances aparte**— y después **`npm run check`**, **nunca a la vez**.
4. En primer plano y con *timeout* amplio.
5. **Una vuelta completa si pasa.** Si la primera destapa trabajo real, arreglarlo y repetir es lo correcto; lo que se evita es repetir para confirmar lo que ya salió verde.

## 2. Qué vas a hacer

La pantalla donde un desconocido con un enlace se convierte en cliente. `T-045` dejó la transacción escrita, probada y sin quien la llame: tú pones las rutas, el formulario, la página de gracias y el correo.

Es la última milla del producto entero: catálogo → filtros → ficha → **este formulario**. Si algo aquí pierde datos escritos, bloquea un teléfono legítimo o no deja claro cómo se paga, todo lo anterior no sirvió.

## 3. Lee primero

| Documento | Para qué |
|---|---|
| [docs/specs/20-apartado-y-cronograma.md](../specs/20-apartado-y-cronograma.md) → `REQ-LAY-001` | **RB-3, RB-5, RB-10, RB-12** y los *sad paths* SP-1 a SP-7: son tu `422` por campo |
| [docs/specs/10-identidad-y-autenticacion.md](../specs/10-identidad-y-autenticacion.md) → **`REQ-IDN-005`** | el consentimiento entero; **RB-4 y SP-4** deciden qué se enlaza (§6) |
| [docs/specs/06-plan-de-tareas.md](../specs/06-plan-de-tareas.md) → **`T-045`** y **`T-045b`** | lo que la transacción ya hace y lo que te toca a ti |
| [docs/specs/31-backoffice-inventario.md](../specs/31-backoffice-inventario.md) → la nota de `T-028` sobre `react-form` | **la decisión de §7**, que es tuya y saca o deja una dependencia |
| `app/Actions/Layaway/CreateLayawayAction.php` | tu único punto de entrada: lee sus excepciones, son tus `422` |
| `app/Http/Resources/ProductDetailResource.php` y `resources/js/pages/catalog/ProductShow.tsx` | de dónde vienes: el CTA que hoy no lleva a ningún sitio |
| `app/Domain/Catalog/FinancingPlan.php` | **la misma cuenta que enseña la ficha**, y la trampa de §5 |
| `resources/js/pages/marketing/Legal.tsx` | las páginas que vas a enlazar, con su marca `drafted: false` |

## 4. Alcance

**Sí:**

1. **Las rutas** `GET|POST /c/{catalog:slug}/apartar/{product:slug}`, en el grupo `catalog-tenant`.
2. **`LayawayController`** y **`StoreLayawayRequest`**.
3. **El formulario**, con `useLayawayForm` y `TermsConsentField`.
4. **La página de gracias** con el código visible y las cuentas de cobro.
5. **El correo de confirmación**, solo si hay email y solo si el apartado **se acaba de crear**.
6. **Enlazar el CTA «Apartar»** de la ficha, que `T-035` dejó sin destino.
7. **El caso de concurrencia que falta** (§8).

**No:**

- **No** toques `CreateLayawayAction` ni la máquina de estados: están cerradas. Si necesitas algo que no expone, dilo en el informe en vez de abrirlas.
- **No** emitas `layaway_created` (RB-11): es **`T-084`**.
- **No** construyas la subida de comprobante: el CTA de la página de gracias apunta a **`T-059`**; deja el enganche nombrado y su caso diferido.
- **No** inventes un ajuste para las condiciones de apartado del negocio (§6.3).

## 5. La cuota que enseñas tiene que ser la del apartado, no la de la ficha × cantidad

`T-041` lo dejó fijado y es la trampa más fácil de esta pantalla: **`Money::allocateEvenly()` no es lineal.** $50 financiados por unidad en 3 cuotas dan `1666, 1666, 1668`; multiplicar por dos da `3332, 3332, 3336`, y el apartado real de 2 unidades es `3333, 3333, 3334`.

La ficha enseña el plan de **una** unidad, porque la cantidad todavía no se ha elegido. Aquí sí se elige, así que:

- **Cuando el cliente cambia la cantidad, el resumen se recalcula sobre el total**, nunca multiplicando la cuota que venía de la ficha.
- **La cuenta la hace el servidor.** Es la regla que `T-027` dejó escrita y que toda la superficie pública respeta: al cliente le llega el `MoneyView` ya formateado, no el precio en centavos y el número de cuotas para que el `.tsx` divida. Mira cómo lo resolvió la previsualización del formulario de producto —una recarga parcial de Inertia con `only`—, que es el patrón del proyecto para las previsualizaciones en vivo.
- **Y tiene que coincidir con lo que se cobrará.** `LayawayTerms::financingPlan()` es la única cuenta; el resumen sale de ahí o de nada.

## 6. El consentimiento, y la decisión que no es técnica

`REQ-IDN-005` pide una casilla obligatoria, y que se persistan fecha e IP **como evidencia** de que alguien aceptó unas condiciones. `T-043` dejó el sitio donde se guarda; `T-045` lo pasa a la acción.

Lo que queda abierto, y decides tú:

1. **`/terminos` y `/privacidad` existen y no están redactadas.** `T-037` las dejó con `drafted: false` y un aviso que dice, literalmente, que la página no tiene valor legal y que no se acepte nada en su nombre. **No se puede pedir a nadie que acepte un texto que no existe.** O se redactan —y entonces `MarketingPagesTest` se pone rojo y hay que quitarle la marca—, o esta tarea decide y **escribe** qué se enseña mientras tanto. Las dos salidas son defendibles; lo que no vale es una casilla que diga «acepto» apuntando a una página que dice «esto no vale».
2. **El texto deja claro con quién es el contrato** (RB-4): el pago es con **el negocio**, no con Apartar. Es la frase que evita que un cliente reclame a la plataforma una deuda que tiene con su ferretería.
3. **Las condiciones propias del negocio no tienen dónde definirse.** RB-4 las menciona «si las define» y **no hay ajuste para eso** en `SettingKey` — lo comprobé. Así que SP-4 es el caso permanente en v1: se enlazan solo las de la plataforma. **No inventes el ajuste**; si crees que hace falta, dilo en el informe.
4. **La IP solo es fiable detrás de un proxy de confianza** (RB-6). `TrustProxies` es de `T-087`, así que hasta el despliegue lo que se guarda es lo que reporta Laravel. Es una limitación conocida (SP-3), no un fallo tuyo: no la tapes, déjala dicha.

## 7. `react-form` está en el stack por esta pantalla, y tú decides si se queda

[05 §6.2] y `REQ-QAX-002` RB-4 declaran `@tanstack/react-form` «en los tres formularios complejos». `T-027` y `T-028` resolvieron el formulario de producto con el `useForm` de Inertia y lo razonaron: meter un segundo motor dentro del mismo `<form>` parte en dos la fuente de `errors` y de `processing`.

**Este es el único llamante planificado que queda.** Si también sale con Inertia, la dependencia no tiene ningún usuario y sale del *stack*.

Decide con el criterio de siempre —lo que hace falta, no lo que estaba escrito—, y **escríbelo en la spec** junto a la nota de `T-028`. Si sale, quítala de `package.json` y de la lista de [05 §6.2]; si se queda, di qué le pide este formulario que Inertia no da. Lo que no puede pasar es que quede en el *stack* sin usarla: una dependencia sin llamante es una que nadie actualiza y nadie sabe por qué está.

## 8. La carrera que falta es el doble clic

`T-045` dejó `CreateLayawayConcurrencyTest` con dos casos —dos apartados de la última unidad con **claves distintas**, y la misma clave con **otro producto**— y ninguno pasa por la segunda comprobación de la clave bajo el bloqueo.

Falta justo la combinación del medio, que es **la única que ocurre de verdad**: un cliente que pulsa «Apartar» dos veces manda el **mismo UUID, del mismo formulario, para el mismo producto**. Eso es lo que impide que un doble clic cree dos apartados de la última unidad, y ahora mismo no lo afirma ningún test. No es un hueco de instrumentación como los otros: es una garantía sin prueba.

Y es la otra cara de una decisión del formulario: **el UUID se genera una vez al montar**, no una por envío. Si se regenerase, la idempotencia no tendría nada que comparar y el doble clic crearía dos apartados — con el test en verde, porque estaría probando otra cosa.

## 9. Cinco cosas que se tuercen solas

1. **Un `422` no pierde lo escrito** (SP-6, SP-1 de `REQ-IDN-005`). Quien rellena este formulario está a punto de comprometerse a pagar; devolverle la pantalla en blanco es perderlo. Cada excepción de la acción va a **su campo**, y el resto del formulario sobrevive.
2. **Sin email, la pantalla es el único canal y lo dice** (RB-10). No basta con enseñar el código: hay que advertir de que no llegará ningún correo e invitar a anotarlo. Un cliente que cierra esa pestaña sin copiar el código pierde el acceso a su apartado hasta que llame al negocio.
3. **El aviso del teléfono no bloquea** (`T-040`, `T-045`). `PhoneNumber::isRecognised()` responde si el prefijo está en el censo; si no lo está, se avisa —«no reconocemos ese prefijo, ¿es correcto?»— y **se deja seguir**. Rechazar un número real pierde la venta sin dejar rastro en ningún log.
4. **`quantity` entre 1 y `min(stock, 10)`** (RB-5), y el `maxQuantity` ya viaja desde la ficha. La validación de cliente y la de servidor tienen que decir lo mismo: si difieren, gana la del servidor y el cliente ve un error que no entiende.
5. **El correo sale solo si el apartado se acaba de crear.** La acción devuelve el mismo apartado ante una clave repetida, con `wasRecentlyCreated = false`; mandar el correo en ese caso le escribe dos veces a alguien que pulsó dos veces.

## 10. Hecho cuando

- [ ] **El CTA de la ficha lleva al formulario**, y el formulario llega con el producto y `quantity = 1`.
- [ ] **El resumen del plan coincide con lo que se cobrará**, recalculado en el servidor al cambiar la cantidad (§5).
- [ ] **`quantity` acotada a `min(stock, 10)`** en cliente y servidor (RB-5).
- [ ] **Cada precondición vuelve como `422` a su campo, sin perder el formulario** (SP-1 a SP-6).
- [ ] **Doble envío devuelve el mismo `code`** y **no manda un segundo correo** (RB-12, §9.5).
- [ ] **El UUID se genera una vez al montar** (§8).
- [ ] **El caso de concurrencia del doble clic** está escrito y en verde (§8).
- [ ] **Sin email, la pantalla avisa de que es el único canal** e invita a anotar el código (RB-10).
- [ ] **La página de gracias enseña el código y las cuentas de cobro** del negocio (RB-10).
- [ ] **La casilla de términos es obligatoria**, valida en servidor (RB-1), y **la decisión de §6.1 está escrita en la spec**.
- [ ] **La decisión de `react-form` está tomada y escrita** (§7), y el `package.json` es coherente con ella.
- [ ] **El aviso de prefijo no reconocido no bloquea** (§9.3).
- [ ] **Ningún emisor de telemetría**; el CTA del comprobante queda nombrado para `T-059` con su caso diferido.
- [ ] `tenancy:audit` y la suite de tenancy en verde.
- [ ] `composer check` y `npm run check` en verde, uno detrás de otro.
- [ ] **Commit hecho.**

## 11. Reporta al terminar

1. Qué enseña el resumen para 2 unidades de un producto con $50 financiados por unidad y 3 cuotas, y de dónde sale esa cifra.
2. Qué decidiste en §6.1 sobre la casilla y los documentos sin redactar, y qué escribiste en la spec.
3. Qué decidiste sobre `react-form`, con el motivo, y qué cambió en `package.json` y en [05 §6.2].
4. Cómo montaste el caso del doble clic y qué falla si el UUID se regenera en cada envío.
5. Qué excepción de la acción va a qué campo, en una lista.
6. Qué queda de `REQ-LAY-001` y `REQ-IDN-005` sin cerrar y de qué tarea es.
7. Cuánto tardó la verificación final y **cuántas vueltas completas** hiciste.
8. Nada más. No propongas siguientes pasos.
